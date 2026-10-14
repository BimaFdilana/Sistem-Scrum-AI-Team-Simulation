<?php

namespace Tests\Feature;

use App\Models\ExecutionRun;
use App\Models\PermissionRequest;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PermissionCancelExpiryTest extends TestCase
{
    use RefreshDatabase;

    private function seedAwaitingPermission(): array
    {
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
        $permission = PermissionRequest::query()->create([
            'execution_run_id' => $run->id, 'title' => 'Izin', 'status' => 'PENDING',
            'requested_by' => $owner->id,
        ]);

        return compact('owner', 'project', 'story', 'run', 'permission');
    }

    public function test_cancel_marks_cancelled_and_frees_story_for_re_execution(): void
    {
        Queue::fake();
        ['owner' => $owner, 'story' => $story, 'run' => $run, 'permission' => $permission]
            = $this->seedAwaitingPermission();

        $this->actingAs($owner)->post("/accepted/{$permission->id}/cancel")
            ->assertRedirect()->assertSessionHas('status');

        $this->assertSame('CANCELLED', $permission->fresh()->status);
        $this->assertSame($owner->id, $permission->fresh()->resolved_by);
        $this->assertSame('FAILED', $run->fresh()->status);
        $this->assertSame('PERMISSION_CANCELLED', $run->fresh()->error_code);

        // Story tidak lagi diblokir: eksekusi baru bisa dibuat.
        $this->actingAs($owner)->post("/stories/{$story->id}/executions")
            ->assertRedirect();
        $this->assertDatabaseHas('execution_runs', ['story_id' => $story->id, 'status' => 'QUEUED']);
    }

    public function test_cancel_only_when_pending(): void
    {
        ['owner' => $owner, 'permission' => $permission] = $this->seedAwaitingPermission();
        $permission->forceFill(['status' => 'APPROVED'])->save();

        $this->actingAs($owner)->post("/accepted/{$permission->id}/cancel")
            ->assertSessionHasErrors('permission');

        $this->assertSame('APPROVED', $permission->fresh()->status);
    }

    public function test_expired_request_does_not_block_new_execution(): void
    {
        Queue::fake();
        ['owner' => $owner, 'story' => $story, 'run' => $run, 'permission' => $permission]
            = $this->seedAwaitingPermission();
        $permission->forceFill(['created_at' => now()->subHours(25)])->save();

        $this->actingAs($owner)->post("/stories/{$story->id}/executions")
            ->assertRedirect();

        $this->assertSame('EXPIRED', $permission->fresh()->status);
        $this->assertSame('FAILED', $run->fresh()->status);
        $this->assertSame('PERMISSION_EXPIRED', $run->fresh()->error_code);
        $this->assertDatabaseHas('execution_runs', ['story_id' => $story->id, 'status' => 'QUEUED']);
    }

    public function test_approve_after_expiry_is_rejected(): void
    {
        ['owner' => $owner, 'permission' => $permission] = $this->seedAwaitingPermission();
        $permission->forceFill(['created_at' => now()->subHours(25)])->save();

        $this->actingAs($owner)->post("/accepted/{$permission->id}/approve")
            ->assertSessionHasErrors('permission');

        $this->assertSame('EXPIRED', $permission->fresh()->status);
    }

    public function test_fresh_pending_request_still_blocks_execution(): void
    {
        Queue::fake();
        ['owner' => $owner, 'story' => $story] = $this->seedAwaitingPermission();

        $this->actingAs($owner)->post("/stories/{$story->id}/executions")
            ->assertSessionHasErrors('execution');

        $this->assertSame(1, ExecutionRun::query()->where('story_id', $story->id)->count());
    }
}
