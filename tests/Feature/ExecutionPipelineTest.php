<?php

namespace Tests\Feature;

use App\Jobs\ExecuteExecutionTaskJob;
use App\Models\ExecutionRun;
use App\Models\PermissionRequest;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use App\Support\Workspace\PatchApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ExecutionPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_patch_applier_blocks_path_traversal_and_git(): void
    {
        $dir = storage_path('framework/testing/patch-'.uniqid());
        mkdir($dir, 0700, true);
        $patcher = new PatchApplier;

        foreach (['../evil.php', '/abs/path.php', '.git/config', 'a/../../b.php'] as $bad) {
            try {
                $patcher->apply($dir, [['path' => $bad, 'action' => 'create', 'content' => 'x']]);
                $this->fail("Path {$bad} seharusnya ditolak.");
            } catch (\RuntimeException) {
                $this->assertTrue(true);
            }
        }

        $applied = $patcher->apply($dir, [
            ['path' => 'app/Http/X.php', 'action' => 'create', 'content' => '<?php // ok'],
            ['path' => 'old.php', 'action' => 'delete'],
        ]);
        $this->assertSame(['app/Http/X.php', 'old.php'], $applied);
        $this->assertSame('<?php // ok', file_get_contents($dir.'/app/Http/X.php'));
    }

    public function test_execution_requires_estimate_and_baseline(): void
    {
        $owner = User::factory()->create();
        $project = Project::query()->create([
            'name' => 'P', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'BASELINE_READY',
            'workspace_path' => storage_path('framework/testing'),
        ]);
        $story = Story::query()->create([
            'project_id' => $project->id, 'title' => 'S', 'estimate_points' => null,
        ]);

        $this->actingAs($owner)->post("/stories/{$story->id}/executions")
            ->assertSessionHasErrors('execution');

        $story->forceFill(['estimate_points' => 5])->save();
        Queue::fake();
        $this->actingAs($owner)->post("/stories/{$story->id}/executions")
            ->assertRedirect();
        $this->assertDatabaseHas('execution_runs', ['story_id' => $story->id, 'status' => 'QUEUED']);
    }

    public function test_accepted_approve_dispatches_task_jobs(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $project = Project::query()->create([
            'name' => 'P', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'BASELINE_READY',
            'workspace_path' => storage_path('framework/testing'),
        ]);
        $story = Story::query()->create([
            'project_id' => $project->id, 'title' => 'S', 'estimate_points' => 5,
        ]);
        $run = ExecutionRun::query()->create([
            'story_id' => $story->id, 'requested_by' => $owner->id, 'status' => 'AWAITING_PERMISSION',
        ]);
        $task = $run->tasks()->create([
            'title' => 'T1', 'description' => 'D1', 'assignee' => 'backend-developer', 'status' => 'PENDING',
        ]);
        $permission = PermissionRequest::query()->create([
            'execution_run_id' => $run->id, 'title' => 'Izin', 'status' => 'PENDING',
            'requested_by' => $owner->id,
        ]);

        $this->actingAs($owner)->post("/accepted/{$permission->id}/approve")
            ->assertRedirect()->assertSessionHas('status');

        $this->assertSame('APPROVED', $permission->fresh()->status);
        $this->assertSame('EXECUTING', $run->fresh()->status);
        Queue::assertPushed(ExecuteExecutionTaskJob::class, fn ($job) => $job->taskId === $task->id);
    }

    public function test_qa_revision_limit_is_two(): void
    {
        $this->assertSame(2, \App\Jobs\ReviewExecutionTaskJob::MAX_REVISIONS);
    }
}
