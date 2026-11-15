<?php

namespace Tests\Feature;

use App\Models\ExecutionRun;
use App\Models\ExecutionTask;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use App\Support\Execution\ExecutionStopper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Aktivitas proses hanya menampilkan yang masih berproses atau benar-benar menunggu Anda. */
class ActivityHidesFinishedRunsTest extends TestCase
{
    use RefreshDatabase;

    private function story(string $title = 'S'): Story
    {
        $project = Project::query()->firstOrCreate(['name' => 'AH'], ['project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'BASELINE_READY']);

        return Story::query()->create(['project_id' => $project->id, 'title' => $title, 'estimate_points' => 3]);
    }

    private function makeRun(Story $story, string $status, array $extra = []): ExecutionRun
    {
        return ExecutionRun::query()->create(['story_id' => $story->id, 'requested_by' => User::factory()->create()->id, 'status' => $status] + $extra);
    }

    private function ids(): array
    {
        return collect($this->actingAs(User::factory()->create())->getJson(route('activity.active'))->assertOk()->json('runs'))->pluck('id')->all();
    }

    public function test_run_stopped_by_po_is_not_listed(): void
    {
        $stopped = $this->makeRun($this->story('a'), 'FAILED', ['error_code' => ExecutionStopper::CODE]);
        $failed = $this->makeRun($this->story('b'), 'FAILED', ['error_code' => 'EXECUTION_FAILED']);

        $ids = $this->ids();
        $this->assertNotContains($stopped->id, $ids);
        $this->assertContains($failed->id, $ids); // gagal dan belum diputuskan: PO masih perlu tahu
    }

    public function test_run_is_hidden_once_the_po_decided_the_story_or_a_newer_run_exists(): void
    {
        $decided = $this->story('d');
        $old = $this->makeRun($decided, 'FAILED');
        $decided->forceFill(['acceptance_status' => 'GAGAL', 'accepted_at' => now(), 'accepted_execution_run_id' => $old->id])->save();

        $retried = $this->story('r');
        $first = $this->makeRun($retried, 'FAILED');
        $second = $this->makeRun($retried, 'EXECUTING');

        $ids = $this->ids();
        $this->assertNotContains($old->id, $ids);
        $this->assertNotContains($first->id, $ids);
        $this->assertContains($second->id, $ids);
    }

    public function test_cancelled_tasks_are_not_shown_inside_a_run(): void
    {
        $run = $this->makeRun($this->story('t'), 'EXECUTING');
        ExecutionTask::query()->create(['execution_run_id' => $run->id, 'title' => 'Berjalan', 'assignee' => 'backend-developer', 'status' => 'IN_PROGRESS']);
        ExecutionTask::query()->create(['execution_run_id' => $run->id, 'title' => 'Dibatalkan', 'assignee' => 'frontend-developer', 'status' => 'CANCELLED']);

        $payload = $this->actingAs(User::factory()->create())->getJson(route('activity.active'))->assertOk()->json('runs.0');

        $this->assertSame(['Berjalan'], collect($payload['tasks'])->pluck('title')->all());
        $this->assertSame(1, $payload['progress']['total']);
    }
}
