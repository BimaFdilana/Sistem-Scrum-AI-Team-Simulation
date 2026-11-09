<?php

namespace Tests\Feature;

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

class FindingDialogueTest extends TestCase
{
    use RefreshDatabase;

    private const QA_REJECT = '{"approved":false,"findings":[{"file":"app/Foo.php","line":12,"severity":"high","instruction":"Validasi input","verify":"kirim kosong"}]}';

    private const QA_APPROVE = '{"approved":true,"criteria":[{"criterion":"c","met":true,"evidence":"app/Foo.php"}]}';

    protected function setUp(): void
    {
        parent::setUp();
        config(['execution.qa_max_repair_rounds' => 3]);
    }

    private function makeRun(int $qaRounds = 0): ExecutionRun
    {
        $dir = storage_path('framework/testing/fd-'.uniqid());
        mkdir($dir, 0700, true);
        $project = Project::query()->create([
            'name' => 'FD', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel',
            'status' => 'BASELINE_READY', 'workspace_path' => $dir,
        ]);
        $story = Story::query()->create([
            'project_id' => $project->id, 'title' => 'S', 'estimate_points' => 3, 'acceptance_criteria' => 'Input divalidasi.',
        ]);
        $run = ExecutionRun::query()->create([
            'story_id' => $story->id, 'requested_by' => User::factory()->create()->id,
            'status' => 'QA_REVIEW', 'mode' => 'direct', 'qa_rounds' => $qaRounds,
        ]);
        $run->tasks()->create(['title' => 'T', 'description' => 'D', 'assignee' => 'backend-developer', 'status' => 'QA_PENDING']);

        return $run;
    }

    /**
     * @param  string  $qa  jawaban QA
     * @param  string|\Closure  $dev  jawaban developer (string JSON atau closure yang melempar)
     * @param  string|\Closure  $sm  jawaban SM
     */
    private function review(ExecutionRun $run, string $qa, string|\Closure $dev = '{"responses":[]}', string|\Closure $sm = '{"decisions":[]}'): void
    {
        $agents = Mockery::mock(AgentProfileClient::class);
        $agents->shouldReceive('sendMessage')->andReturnUsing(function (string $slug, string $prompt) use ($qa, $dev, $sm) {
            $answer = match (true) {
                str_contains($prompt, 'Kamu QA Engineer') => $qa,
                str_contains($prompt, 'penengah') => $sm,
                default => $dev,
            };

            return $answer instanceof \Closure ? $answer() : $answer;
        });
        $worktrees = Mockery::mock(WorktreeManager::class);
        $worktrees->shouldReceive('diff')->andReturn('diff');
        $worktrees->shouldReceive('changedFiles')->andReturn(['app/Foo.php']);
        $worktrees->shouldReceive('head')->andReturn(str_repeat('a', 40));

        (new BatchReviewExecutionJob($run->id))->handle($agents, $worktrees, app(BatchQa::class));
    }

    public function test_agreeing_developer_gets_open_finding_to_fix(): void
    {
        Queue::fake();
        $run = $this->makeRun();
        $id = 1;

        $this->review($run, self::QA_REJECT, '{"responses":[{"id":1,"stance":"agree","reason":"benar"}]}');

        $finding = ExecutionFinding::query()->findOrFail($id);
        $this->assertSame('OPEN', $finding->status);
        $this->assertSame('agree', $finding->dev_stance);
        $this->assertSame('high', $finding->severity);
        $this->assertSame(12, $finding->line);
        $this->assertSame('REVISION', $run->tasks()->first()->status);
        $this->assertStringContainsString('[#1]', $run->tasks()->first()->qa_feedback);
        Queue::assertPushed(ExecuteExecutionTaskJob::class);
    }

    public function test_sm_sides_with_developer_closes_finding_and_run_completes(): void
    {
        Queue::fake();
        $run = $this->makeRun();

        $this->review(
            $run,
            self::QA_REJECT,
            '{"responses":[{"id":1,"stance":"disagree","reason":"sudah divalidasi di FormRequest"}]}',
            '{"decisions":[{"id":1,"winner":"developer","reason":"FormRequest memvalidasi"}]}',
        );

        $finding = ExecutionFinding::query()->firstOrFail();
        $this->assertSame('DISMISSED', $finding->status);
        $this->assertSame('developer', $finding->sm_winner);
        $this->assertSame('COMPLETED', $run->fresh()->status);
        Queue::assertNotPushed(ExecuteExecutionTaskJob::class);
    }

    public function test_sm_sides_with_qa_forces_the_fix_and_says_so(): void
    {
        Queue::fake();
        $run = $this->makeRun();

        $this->review(
            $run,
            self::QA_REJECT,
            '{"responses":[{"id":1,"stance":"disagree","reason":"tidak perlu"}]}',
            '{"decisions":[{"id":1,"winner":"qa","reason":"criteria meminta validasi"}]}',
        );

        $this->assertSame('OPEN', ExecutionFinding::query()->firstOrFail()->status);
        $this->assertStringContainsString('SM memutuskan: wajib diperbaiki', $run->tasks()->first()->qa_feedback);
        Queue::assertPushed(ExecuteExecutionTaskJob::class);
    }

    public function test_agent_failures_keep_qa_decision_instead_of_breaking_the_run(): void
    {
        Queue::fake();
        $run = $this->makeRun();
        $boom = fn () => throw new \RuntimeException('gateway down');

        $this->review($run, self::QA_REJECT, $boom, $boom);

        $this->assertSame('OPEN', ExecutionFinding::query()->firstOrFail()->status);
        $this->assertSame('REVISION', $run->tasks()->first()->status);
        Queue::assertPushed(ExecuteExecutionTaskJob::class);
    }

    public function test_recheck_closes_resolved_findings_when_qa_approves(): void
    {
        Queue::fake();
        $run = $this->makeRun(qaRounds: 1);
        $finding = ExecutionFinding::query()->create([
            'execution_run_id' => $run->id, 'file' => 'app/Foo.php', 'issue' => 'Validasi', 'status' => 'OPEN', 'round' => 1,
        ]);

        $this->review($run, self::QA_APPROVE);

        $this->assertSame('RESOLVED', $finding->fresh()->status);
        $this->assertSame('COMPLETED', $run->fresh()->status);
    }

    public function test_final_round_hands_over_with_both_sides_arguments(): void
    {
        Queue::fake();
        config(['execution.qa_max_repair_rounds' => 1]);
        $run = $this->makeRun(qaRounds: 1);
        ExecutionFinding::query()->create([
            'execution_run_id' => $run->id, 'file' => 'app/Foo.php', 'issue' => 'Validasi hilang', 'status' => 'OPEN', 'round' => 1,
            'dev_stance' => 'disagree', 'dev_reason' => 'sudah ada di middleware', 'sm_winner' => 'qa', 'sm_reason' => 'criteria jelas',
        ]);

        $this->review($run, '{"approved":false,"findings":[{"file":"app/Foo.php","instruction":"Masih belum","verify":""}]}');

        $fresh = $run->fresh();
        $this->assertSame('NEEDS_PO', $fresh->status);
        $this->assertSame('QA_REJECTED_AFTER_REPAIR', $fresh->error_code);
        $this->assertStringContainsString('sudah ada di middleware', $run->messages()->latest('id')->first()->content);
        $this->assertSame('QA_PENDING', $run->tasks()->first()->status);
    }
}
