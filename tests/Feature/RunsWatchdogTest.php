<?php

namespace Tests\Feature;

use App\Jobs\BatchReviewExecutionJob;
use App\Jobs\ExecuteExecutionTaskJob;
use App\Models\ExecutionRun;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use App\Services\AgentToolRunner;
use App\Support\Workspace\WorktreeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RunsWatchdogTest extends TestCase
{
    use RefreshDatabase;

    private function runWithStalledTask(int $stallCount = 0): array
    {
        $project = Project::query()->create([
            'name' => 'WD', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'BASELINE_READY',
        ]);
        $story = Story::query()->create(['project_id' => $project->id, 'title' => 'S', 'estimate_points' => 3]);
        $run = ExecutionRun::query()->create([
            'story_id' => $story->id, 'requested_by' => User::factory()->create()->id, 'status' => 'EXECUTING',
        ]);
        $task = $run->tasks()->create([
            'title' => 'T', 'description' => 'D', 'assignee' => 'backend-developer', 'status' => 'IN_PROGRESS',
            'stall_count' => $stallCount, 'last_progress_at' => now()->subHour(),
        ]);

        return [$story, $run, $task];
    }

    public function test_stalled_task_is_requeued_and_redispatched(): void
    {
        Queue::fake();
        [, $run, $task] = $this->runWithStalledTask();

        $this->artisan('runs:watchdog')->assertSuccessful();

        $task->refresh();
        $this->assertSame('PENDING', $task->status);
        $this->assertSame(1, $task->stall_count);
        $this->assertSame('EXECUTING', $run->fresh()->status);
        Queue::assertPushed(ExecuteExecutionTaskJob::class, fn ($job) => $job->taskId === $task->id);
    }

    public function test_task_stalling_too_often_hands_run_to_po(): void
    {
        Queue::fake();
        [, $run, $task] = $this->runWithStalledTask(stallCount: 2);

        $this->artisan('runs:watchdog')->assertSuccessful();

        $this->assertSame('NEEDS_PO', $run->fresh()->status);
        $this->assertSame('WATCHDOG_STALLED', $run->fresh()->error_code);
        $this->assertSame('BLOCKED', $task->fresh()->status);
        Queue::assertNotPushed(ExecuteExecutionTaskJob::class);
    }

    public function test_po_can_resume_run_waiting_for_decision(): void
    {
        Queue::fake();
        [$story, $run, $task] = $this->runWithStalledTask(stallCount: 2);
        $this->artisan('runs:watchdog');

        $this->actingAs(User::factory()->create())
            ->post(route('stories.executions.resume', [$story, $run]))
            ->assertRedirect();

        $this->assertSame('EXECUTING', $run->fresh()->status);
        $this->assertSame(0, $task->fresh()->stall_count);
        Queue::assertPushed(ExecuteExecutionTaskJob::class, fn ($job) => $job->taskId === $task->id);
    }

    public function test_resume_is_refused_when_run_is_not_waiting_for_po(): void
    {
        Queue::fake();
        [$story, $run] = $this->runWithStalledTask();

        $this->actingAs(User::factory()->create())
            ->post(route('stories.executions.resume', [$story, $run]))
            ->assertSessionHasErrors('acceptance');

        $this->assertSame('EXECUTING', $run->fresh()->status);
    }

    public function test_run_waiting_for_po_can_be_closed_as_failed_but_not_as_success(): void
    {
        Queue::fake();
        [$story, $run] = $this->runWithStalledTask(stallCount: 2);
        $this->artisan('runs:watchdog');
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('stories.executions.accept', [$story, $run]), ['status' => 'BERHASIL'])
            ->assertSessionHasErrors('acceptance');
        $this->assertNotSame('BERHASIL', $story->fresh()->acceptance_status);

        $this->actingAs($user)->post(route('stories.executions.accept', [$story, $run]), ['status' => 'GAGAL']);
        $this->assertSame('GAGAL', $story->fresh()->acceptance_status);
    }

    public function test_stale_job_cannot_overwrite_needs_po_status(): void
    {
        Queue::fake();
        [, $run] = $this->runWithStalledTask(stallCount: 2);
        $stale = ExecutionRun::query()->find($run->id); // dimuat sebelum watchdog berjalan
        $this->artisan('runs:watchdog');

        $stale->forceFill(['status' => 'REVIEWING'])->save();

        $this->assertSame('NEEDS_PO', $run->fresh()->status);
    }

    public function test_duplicate_job_for_already_claimed_task_does_nothing(): void
    {
        [, $run, $task] = $this->runWithStalledTask(); // sudah IN_PROGRESS

        (new ExecuteExecutionTaskJob($run->id, $task->id))->handle(
            app(WorktreeManager::class),
            app(AgentToolRunner::class),
        );

        $this->assertSame('IN_PROGRESS', $task->fresh()->status);
        $this->assertSame(0, (int) $task->fresh()->attempt_seq);
    }

    public function test_batch_qa_that_never_finished_hands_over_and_resume_reruns_qa(): void
    {
        Queue::fake();
        [, $run] = $this->runWithStalledTask();
        $run->tasks()->update(['status' => 'QA_PENDING', 'last_progress_at' => now()->subHours(3)]);
        ExecutionRun::query()->whereKey($run->id)->update(['status' => 'QA_REVIEW', 'qa_started_at' => now()->subHours(3), 'updated_at' => now()->subHours(3)]);

        $this->artisan('runs:watchdog')->assertSuccessful();

        $fresh = $run->fresh();
        $this->assertSame('NEEDS_PO', $fresh->status);
        $this->assertSame('QA_STALLED', $fresh->error_code);

        $this->assertTrue($fresh->resumeFromPo());
        $this->assertSame('QA_REVIEW', $run->fresh()->status);
        Queue::assertPushed(BatchReviewExecutionJob::class);
    }
}
