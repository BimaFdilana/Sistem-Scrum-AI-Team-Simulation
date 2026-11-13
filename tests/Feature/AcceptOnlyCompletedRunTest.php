<?php

namespace Tests\Feature;

use App\Models\ExecutionRun;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Berhasil hanya untuk eksekusi yang selesai; eksekusi gagal harus ditandai Gagal lalu diulang. */
class AcceptOnlyCompletedRunTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(string $status, Story $story, User $user): ExecutionRun
    {
        return ExecutionRun::query()->create(['story_id' => $story->id, 'requested_by' => $user->id, 'status' => $status, 'mode' => 'worktree']);
    }

    private function story(): Story
    {
        $project = Project::query()->create(['name' => 'P', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'BASELINE_READY']);

        return Story::create(['project_id' => $project->id, 'title' => 'Pagu', 'estimate_points' => 5]);
    }

    public function test_failed_blocked_or_stopped_run_cannot_be_accepted_as_berhasil(): void
    {
        $user = User::factory()->create();
        foreach (['FAILED', 'BLOCKED', ExecutionRun::NEEDS_PO] as $status) {
            $story = $this->story();
            $run = $this->makeRun($status, $story, $user);

            $this->actingAs($user)->post(route('stories.executions.accept', [$story, $run]), ['status' => 'BERHASIL'])
                ->assertSessionHasErrors('acceptance');
            $this->assertNull($story->fresh()->acceptance_status, $status);
        }
    }

    public function test_failed_run_can_be_marked_gagal_and_story_reopens_for_rerun(): void
    {
        $user = User::factory()->create();
        $story = $this->story();
        $run = $this->makeRun('FAILED', $story, $user);

        $this->actingAs($user)->get(route('stories.executions.show', [$story, $run]))->assertOk()
            ->assertSee('tidak bisa diterima sebagai Berhasil')->assertDontSee('Terima sebagai Berhasil');
        $this->actingAs($user)->post(route('stories.executions.accept', [$story, $run]), ['status' => 'GAGAL'])->assertSessionHasNoErrors();

        $story->refresh();
        $this->assertSame('GAGAL', $story->acceptance_status);
        $this->assertSame($run->id, $story->accepted_execution_run_id);
        $this->assertFalse($story->isLockedFromExecution());
    }

    public function test_old_failed_run_page_says_the_decision_came_from_another_run(): void
    {
        $user = User::factory()->create();
        $story = $this->story();
        $failed = $this->makeRun('FAILED', $story, $user);
        $done = $this->makeRun('COMPLETED', $story, $user);
        $story->forceFill(['acceptance_status' => 'BERHASIL', 'accepted_at' => now(), 'accepted_execution_run_id' => $done->id])->save();

        $this->actingAs($user)->get(route('stories.executions.show', [$story, $failed]))->assertOk()
            ->assertSee('eksekusi #'.$done->id)->assertSee('tidak ikut ke kode utama')
            ->assertDontSee('Keputusan final PO: Berhasil');
    }
}
