<?php

namespace Tests\Feature;

use App\Console\Commands\RunsWatchdog;
use App\Jobs\BatchReviewExecutionJob;
use App\Jobs\ExecuteExecutionTaskJob;
use App\Models\ExecutionFinding;
use App\Models\ExecutionRun;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use App\Services\AgentProfileClient;
use App\Support\Execution\BatchQa;
use App\Support\Workspace\WorktreeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Skenario ujung ke ujung di tingkat status: macet, QA menolak berulang, lanjut
 * oleh PO, dan batal. Agen AI dimock; yang diuji adalah alur dan keputusan sistem.
 */
class ExecutionLifecycleScenarioTest extends TestCase
{
    use RefreshDatabase;

    private const REJECT = '{"approved":false,"findings":[{"file":"app/Foo.php","instruction":"Validasi hilang","verify":"kirim kosong"}]}';

    private const APPROVE = '{"approved":true,"criteria":[{"criterion":"Input divalidasi.","met":true,"evidence":"app/Foo.php"}]}';

    private function makeRun(string $status = 'QA_REVIEW'): ExecutionRun
    {
        $dir = storage_path('framework/testing/life-'.uniqid());
        mkdir($dir, 0700, true);
        $project = Project::query()->create([
            'name' => 'Life', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel',
            'status' => 'BASELINE_READY', 'workspace_path' => $dir,
        ]);
        $story = Story::query()->create([
            'project_id' => $project->id, 'title' => 'S', 'estimate_points' => 5, 'acceptance_criteria' => 'Input divalidasi.',
        ]);
        $run = ExecutionRun::query()->create([
            'story_id' => $story->id, 'requested_by' => User::factory()->create()->id, 'status' => $status, 'mode' => 'direct',
        ]);
        $run->tasks()->create(['title' => 'T', 'description' => 'D', 'assignee' => 'backend-developer', 'status' => 'QA_PENDING']);

        return $run;
    }

    private function review(ExecutionRun $run, string $qa): void
    {
        $agents = Mockery::mock(AgentProfileClient::class);
        $agents->shouldReceive('sendMessage')->andReturnUsing(fn (string $slug, string $prompt) => match (true) {
            str_contains($prompt, 'Kamu QA Engineer') => $qa,
            str_contains($prompt, 'penengah') => '{"decisions":[{"id":1,"winner":"qa","reason":"criteria jelas"}]}',
            default => '{"responses":[{"id":1,"stance":"agree","reason":"benar"}]}',
        });
        $worktrees = Mockery::mock(WorktreeManager::class);
        $worktrees->shouldReceive('diff')->andReturn('diff');
        $worktrees->shouldReceive('changedFiles')->andReturn(['app/Foo.php']);
        $worktrees->shouldReceive('head')->andReturn(str_repeat('a', 40));

        (new BatchReviewExecutionJob($run->id))->handle($agents, $worktrees, app(BatchQa::class));
    }

    /** Developer selesai memperbaiki: task kembali menunggu QA. */
    private function developerFinishes(ExecutionRun $run): void
    {
        $run->tasks()->update(['status' => 'QA_PENDING']);
        $run->refresh();
        app(BatchQa::class)->maybeStart($run);
    }

    public function test_qa_rejects_three_times_then_po_resumes_and_qa_approves(): void
    {
        Queue::fake();
        config(['execution.qa_max_repair_rounds' => 3]);
        $run = $this->makeRun();

        foreach ([1, 2, 3] as $round) {
            $this->review($run->fresh(), self::REJECT);
            $this->assertSame('EXECUTING', $run->fresh()->status, "putaran {$round}: kembali ke developer");
            $this->assertSame($round, (int) $run->fresh()->qa_rounds);
            $this->developerFinishes($run);
            $this->assertSame('QA_REVIEW', $run->fresh()->status, "putaran {$round}: QA menilai ulang");
        }

        $this->review($run->fresh(), self::REJECT);
        $this->assertSame('NEEDS_PO', $run->fresh()->status);
        $this->assertSame('QA_REJECTED_AFTER_REPAIR', $run->fresh()->error_code);
        $this->assertSame('QA_PENDING', $run->tasks()->first()->status, 'task tidak pernah disetujui diam-diam');
        $this->assertGreaterThanOrEqual(1, ExecutionFinding::query()->where('status', 'OPEN')->count());

        $this->assertTrue($run->fresh()->resumeFromPo());
        $this->assertSame('QA_REVIEW', $run->fresh()->status);

        $this->review($run->fresh(), self::APPROVE);
        $this->assertSame('COMPLETED', $run->fresh()->status);
        $this->assertSame(0, ExecutionFinding::query()->where('status', 'OPEN')->count(), 'persetujuan menutup temuan terbuka');
    }

    public function test_stalled_task_recovers_twice_then_waits_for_po_and_finishes_after_resume(): void
    {
        Queue::fake();
        $run = $this->makeRun('EXECUTING');
        $task = $run->tasks()->first();

        for ($attempt = 1; $attempt <= RunsWatchdog::MAX_AUTO_RESUME; $attempt++) {
            $run->tasks()->update(['status' => 'IN_PROGRESS', 'last_progress_at' => now()->subHours(2)]);
            $this->artisan('runs:watchdog')->assertSuccessful();
            $this->assertSame('PENDING', $task->fresh()->status, "macet ke-{$attempt}: dijalankan ulang");
            $this->assertSame('EXECUTING', $run->fresh()->status);
        }
        Queue::assertPushed(ExecuteExecutionTaskJob::class, RunsWatchdog::MAX_AUTO_RESUME);

        $run->tasks()->update(['status' => 'IN_PROGRESS', 'last_progress_at' => now()->subHours(2)]);
        $this->artisan('runs:watchdog')->assertSuccessful();
        $this->assertSame('NEEDS_PO', $run->fresh()->status);
        $this->assertSame('BLOCKED', $task->fresh()->status);

        $this->assertTrue($run->fresh()->resumeFromPo());
        $this->assertSame('PENDING', $task->fresh()->status);
        $this->assertSame(0, $task->fresh()->stall_count);

        $this->developerFinishes($run);
        $this->review($run->fresh(), self::APPROVE);
        $this->assertSame('COMPLETED', $run->fresh()->status);
    }

    public function test_po_can_cancel_a_stuck_run_as_failed_and_cannot_resume_it_again(): void
    {
        Queue::fake();
        $run = $this->makeRun('EXECUTING');
        $run->tasks()->update(['status' => 'IN_PROGRESS', 'stall_count' => 2, 'last_progress_at' => now()->subHours(2)]);
        $this->artisan('runs:watchdog');
        $this->assertSame('NEEDS_PO', $run->fresh()->status);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('stories.executions.accept', [$run->story, $run]), ['status' => 'GAGAL']);

        $this->assertSame('GAGAL', $run->story->fresh()->acceptance_status);
        $this->actingAs($user)->post(route('stories.executions.resume', [$run->story, $run]))->assertRedirect();
        $this->assertSame('NEEDS_PO', $run->fresh()->status, 'run yang sudah ditutup Gagal tidak boleh dilanjutkan lagi');
    }
}
