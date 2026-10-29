<?php

namespace Tests\Feature;

use App\Jobs\ExecuteExecutionTaskJob;
use App\Jobs\ReviewExecutionTaskJob;
use App\Models\ExecutionRun;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use App\Support\Workspace\PatchApplier;
use App\Support\Workspace\WorktreeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ExecutionPatchRetryTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(string $baseline): array
    {
        $owner = User::factory()->create();
        $project = Project::query()->create([
            'name' => 'PR', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel',
            'status' => 'BASELINE_READY', 'workspace_path' => $baseline,
        ]);
        $story = Story::query()->create([
            'project_id' => $project->id, 'title' => 'S', 'estimate_points' => 5,
        ]);
        $run = ExecutionRun::query()->create([
            'story_id' => $story->id, 'requested_by' => $owner->id, 'status' => 'EXECUTING',
        ]);
        $task = $run->tasks()->create([
            'title' => 'T1', 'description' => 'D1', 'assignee' => 'backend-developer', 'status' => 'PENDING',
        ]);

        return [$run, $task];
    }

    private function makeBaseline(): array
    {
        $baseline = storage_path('framework/testing/patch-retry-'.uniqid());
        mkdir($baseline, 0700, true);
        file_put_contents($baseline.'/README.md', 'baseline');
        $worktrees = new WorktreeManager;
        $worktrees->ensureRepo($baseline);

        return [$baseline, $worktrees];
    }

    private function validPatch(): string
    {
        return json_encode(['files' => [
            ['path' => 'hello.css', 'action' => 'create', 'content' => '.a{color:red}'],
        ], 'summary' => 'Tambah hello.css.']);
    }

    public function test_tool_loop_success_moves_task_to_review(): void
    {
        // Jalur tool-call menggantikan patch JSON: runner mengembalikan
        // daftar file + ringkasan, job memindah task ke IN_REVIEW dan
        // mengantre job review QA.
        Queue::fake();
        [$baseline, $worktrees] = $this->makeBaseline();
        [$run, $task] = $this->makeRun($baseline);

        $runner = $this->mock(\App\Services\AgentToolRunner::class, function ($mock): void {
            $mock->shouldReceive('run')->once()->andReturn(['files' => ['hello.css'], 'summary' => 'Tambah hello.css.']);
        });

        (new ExecuteExecutionTaskJob($run->id, $task->id))->handle($worktrees, $runner);

        $this->assertSame('IN_REVIEW', $task->fresh()->status);
        $this->assertSame('REVIEWING', $run->fresh()->status);
        Queue::assertPushed(ReviewExecutionTaskJob::class);
    }

    public function test_tool_loop_failure_fails_run_only_once(): void
    {
        Queue::fake();
        [$baseline, $worktrees] = $this->makeBaseline();
        [$run, $task] = $this->makeRun($baseline);

        $runner = $this->mock(\App\Services\AgentToolRunner::class, function ($mock): void {
            $mock->shouldReceive('run')->once()->andThrow(new \RuntimeException('Agent gagal.'));
        });

        try {
            (new ExecuteExecutionTaskJob($run->id, $task->id))->handle($worktrees, $runner);
            $this->fail('Job seharusnya melempar ulang exception.');
        } catch (\Throwable) {
            // Diharapkan: runner gagal -> failRun.
        }

        $this->assertSame('FAILED', $task->fresh()->status);
        $this->assertSame('FAILED', $run->fresh()->status);
        $this->assertSame('EXECUTION_FAILED', $run->fresh()->error_code);
    }
}
