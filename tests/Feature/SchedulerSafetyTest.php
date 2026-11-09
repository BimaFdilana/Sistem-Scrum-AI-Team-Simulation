<?php

namespace Tests\Feature;

use App\Jobs\ExecuteExecutionTaskJob;
use App\Jobs\MergeExecutionJob;
use App\Models\ExecutionRun;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use App\Services\ChatDisclosurePolicy;
use App\Services\HermesClient;
use App\Support\Execution\ExecutionScheduler;
use App\Support\Workspace\WorktreeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class SchedulerSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(string $status = 'EXECUTING'): ExecutionRun
    {
        $project = Project::query()->create([
            'name' => 'SS', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel',
            'status' => 'BASELINE_READY', 'workspace_path' => storage_path('framework/testing'),
        ]);
        $story = Story::query()->create(['project_id' => $project->id, 'title' => 'S', 'estimate_points' => 3]);

        return ExecutionRun::query()->create([
            'story_id' => $story->id, 'requested_by' => User::factory()->create()->id, 'status' => $status,
        ]);
    }

    private function task(ExecutionRun $run, string $status, ?string $file, int $order)
    {
        return $run->tasks()->create([
            'title' => 'T'.$order,
            'description' => $file ? "KERJAKAN: x\nFILE: {$file}\nHASIL: y" : 'KERJAKAN: x tanpa daftar file',
            'assignee' => 'backend-developer', 'status' => $status, 'sort_order' => $order,
        ]);
    }

    public function test_running_task_with_unknown_scope_blocks_every_other_task(): void
    {
        $run = $this->makeRun();
        $this->task($run, 'IN_PROGRESS', null, 0);
        $this->task($run, 'PENDING', 'app/Models/Other.php', 1);

        $this->assertSame([], app(ExecutionScheduler::class)->readyTasks($run));
    }

    public function test_running_task_with_known_scope_does_not_block_disjoint_task(): void
    {
        $run = $this->makeRun();
        $this->task($run, 'IN_PROGRESS', 'app/Models/A.php', 0);
        $pending = $this->task($run, 'PENDING', 'app/Models/B.php', 1);

        $ready = app(ExecutionScheduler::class)->readyTasks($run);

        $this->assertSame([$pending->id], array_map(fn ($t) => $t->id, $ready));
    }

    public function test_revision_task_occupies_a_parallel_slot(): void
    {
        Queue::fake();
        config(['execution.max_parallel' => 1]);
        $run = $this->makeRun();
        $this->task($run, 'REVISION', 'app/Models/A.php', 0);
        $this->task($run, 'PENDING', 'app/Models/B.php', 1);

        $dispatched = app(ExecutionScheduler::class)->dispatchReady($run);

        $this->assertSame([], $dispatched);
        Queue::assertNotPushed(ExecuteExecutionTaskJob::class);
    }

    public function test_second_merge_job_is_skipped_while_run_is_locked(): void
    {
        $run = $this->makeRun('MERGING');
        $lock = Cache::lock('merge-run-'.$run->id, 60);
        $this->assertTrue($lock->get());

        $worktrees = Mockery::mock(WorktreeManager::class);
        $worktrees->shouldNotReceive('head');
        (new MergeExecutionJob($run->id))->handle(
            $worktrees,
            Mockery::mock(HermesClient::class),
            app(ChatDisclosurePolicy::class),
        );

        $this->assertSame('MERGING', $run->fresh()->status);
        $lock->release();
    }
}
