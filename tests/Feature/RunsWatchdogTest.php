<?php

namespace Tests\Feature;

use App\Jobs\ExecuteExecutionTaskJob;
use App\Models\ExecutionRun;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
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
}
