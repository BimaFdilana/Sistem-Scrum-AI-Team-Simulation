<?php

namespace Tests\Feature;

use App\Jobs\BatchReviewExecutionJob;
use App\Jobs\ExecuteExecutionTaskJob;
use App\Jobs\ReviewExecutionTaskJob;
use App\Models\ExecutionRun;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use App\Support\Execution\TaskCompletion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TaskCompletionTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(string $qaMode): ExecutionRun
    {
        $project = Project::query()->create(['name' => 'TC', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'BASELINE_READY']);
        $story = Story::query()->create(['project_id' => $project->id, 'title' => 'S', 'estimate_points' => 3]);
        $run = ExecutionRun::query()->create([
            'story_id' => $story->id, 'requested_by' => User::factory()->create()->id, 'status' => 'EXECUTING', 'mode' => 'direct', 'qa_mode' => $qaMode,
        ]);
        $run->tasks()->create(['title' => 'T', 'description' => 'D', 'assignee' => 'backend-developer', 'status' => 'IN_PROGRESS']);

        return $run;
    }

    public function test_batch_mode_sends_the_task_to_batch_qa_for_every_executor(): void
    {
        Queue::fake();
        $run = $this->makeRun('batch');

        app(TaskCompletion::class)->submitForQa($run, $run->tasks()->first(), 'diff');

        $this->assertSame('QA_PENDING', $run->tasks()->first()->status);
        $this->assertSame('QA_REVIEW', $run->fresh()->status);
        Queue::assertPushed(BatchReviewExecutionJob::class);
        Queue::assertNotPushed(ReviewExecutionTaskJob::class);
    }

    public function test_per_task_mode_still_uses_the_per_task_review(): void
    {
        Queue::fake();
        $run = $this->makeRun('per_task');

        app(TaskCompletion::class)->submitForQa($run, $run->tasks()->first(), 'diff');

        $this->assertSame('IN_REVIEW', $run->tasks()->first()->status);
        $this->assertSame('REVIEWING', $run->fresh()->status);
        Queue::assertPushed(ReviewExecutionTaskJob::class);
        Queue::assertNotPushed(BatchReviewExecutionJob::class);
    }

    public function test_no_executor_path_bypasses_the_qa_mode(): void
    {
        // Pagar: hanya TaskCompletion (dan watchdog yang mengulang review yang macet)
        // yang boleh mengirim ReviewExecutionTaskJob. Jalur eksekutor baru yang
        // memanggilnya langsung akan melewati EXECUTION_QA_MODE=batch.
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $name = str_replace(app_path().'/', '', $file->getPathname());
            if (in_array($name, ['Support/Execution/TaskCompletion.php', 'Console/Commands/RunsWatchdog.php'], true)) {
                continue;
            }
            if (str_contains((string) file_get_contents($file->getPathname()), 'ReviewExecutionTaskJob::dispatch')) {
                $offenders[] = $name;
            }
        }

        $this->assertSame([], $offenders, 'kirim ke QA lewat TaskCompletion::submitForQa');
    }

    public function test_batch_mode_dispatches_the_next_pending_task_after_each_completion(): void
    {
        Queue::fake();
        $run = $this->makeRun('batch');
        $second = $run->tasks()->create(['title' => 'T2', 'description' => "KERJAKAN: x\nFILE: app/Two.php", 'assignee' => 'frontend-developer', 'status' => 'PENDING', 'sort_order' => 1]);
        $run->tasks()->where('id', '!=', $second->id)->update(['sort_order' => 0, 'description' => "KERJAKAN: x\nFILE: app/One.php"]);

        app(TaskCompletion::class)->submitForQa($run, $run->tasks()->orderBy('sort_order')->first(), 'diff');

        Queue::assertPushed(ExecuteExecutionTaskJob::class, fn ($job) => $job->taskId === $second->id);
        Queue::assertNotPushed(BatchReviewExecutionJob::class);
        $this->assertSame('EXECUTING', $run->fresh()->status);
    }
}
