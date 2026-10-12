<?php

namespace Tests\Feature;

use App\Models\Actor;
use App\Models\ExecutionRun;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Story;
use App\Models\StoryEstimationRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoryAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_po_accepts_completed_execution_as_berhasil(): void
    {
        [$owner, $project, $story, $run] = $this->makeExecutedStory('COMPLETED');

        $this->actingAs($owner)
            ->post('/stories/'.$story->id.'/executions/'.$run->id.'/accept', ['status' => 'BERHASIL'])
            ->assertRedirect('/stories/'.$story->id.'/executions/'.$run->id)
            ->assertSessionHas('status');

        $story = $story->fresh();
        $this->assertTrue($story->isAccepted());
        $this->assertSame('Berhasil', $story->acceptanceLabel());
        $this->assertNotNull($story->accepted_at);
    }

    public function test_po_marks_blocked_execution_as_gagal(): void
    {
        [$owner, $project, $story, $run] = $this->makeExecutedStory('BLOCKED');

        $this->actingAs($owner)
            ->post('/stories/'.$story->id.'/executions/'.$run->id.'/accept', ['status' => 'GAGAL'])
            ->assertRedirect('/stories/'.$story->id.'/executions/'.$run->id);

        $this->assertSame('Gagal', $story->fresh()->acceptanceLabel());
    }

    public function test_accepted_story_cannot_be_executed_again(): void
    {
        [$owner, $project, $story, $run] = $this->makeExecutedStory('COMPLETED');
        $story->forceFill(['acceptance_status' => 'BERHASIL', 'accepted_at' => now()])->save();

        $this->actingAs($owner)
            ->post('/stories/'.$story->id.'/executions')
            ->assertSessionHasErrors('execution');

        $this->assertSame(1, ExecutionRun::query()->where('story_id', $story->id)->count());
    }

    public function test_accept_requires_terminal_run(): void
    {
        [$owner, $project, $story, $run] = $this->makeExecutedStory('EXECUTING');

        $this->actingAs($owner)
            ->post('/stories/'.$story->id.'/executions/'.$run->id.'/accept', ['status' => 'BERHASIL'])
            ->assertSessionHasErrors('acceptance');

        $this->assertFalse($story->fresh()->isAccepted());
    }

    public function test_accept_rejects_invalid_status(): void
    {
        [$owner, $project, $story, $run] = $this->makeExecutedStory('COMPLETED');

        $this->actingAs($owner)
            ->post('/stories/'.$story->id.'/executions/'.$run->id.'/accept', ['status' => 'OK'])
            ->assertSessionHasErrors('acceptance');

        $this->assertFalse($story->fresh()->isAccepted());
    }

    public function test_acceptance_resets_when_story_revision_changes(): void
    {
        [$owner, $project, $story, $run] = $this->makeExecutedStory('COMPLETED');
        $story->forceFill(['acceptance_status' => 'BERHASIL', 'accepted_at' => now()])->save();

        $story->revision = 2;
        $story->save();

        $fresh = $story->fresh();
        $this->assertFalse($fresh->isAccepted());
        $this->assertNull($fresh->acceptance_status);
    }

    public function test_backlog_locks_execution_button_for_accepted_story(): void
    {
        [$owner, $project, $story, $run] = $this->makeExecutedStory('COMPLETED');
        $story->forceFill(['acceptance_status' => 'GAGAL', 'accepted_at' => now()])->save();

        $this->actingAs($owner)->get('/projects/'.$project->id.'/sprint-backlog')
            ->assertOk()
            ->assertSee('Keputusan final PO:')
            ->assertSee('Gagal')
            ->assertSee('Eksekusi User Story — terkunci');
    }

    public function test_execution_show_displays_accept_buttons_for_terminal_run(): void
    {
        [$owner, $project, $story, $run] = $this->makeExecutedStory('COMPLETED');

        $this->actingAs($owner)->get('/stories/'.$story->id.'/executions/'.$run->id)
            ->assertOk()
            ->assertSee('Terima sebagai Berhasil')
            ->assertSee('Tandai Gagal');
    }

    /** @return array{User, Project, Story, ExecutionRun} */
    private function makeExecutedStory(string $runStatus): array
    {
        $owner = User::factory()->create();
        $project = Project::query()->create([
            'name' => 'Budget Workspace', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'SETUP_REQUIRED',
        ]);
        $sprint = Sprint::query()->create([
            'project_id' => $project->id, 'backlog_id' => $project->backlog->id,
            'name' => 'Sprint 1', 'status' => 'PLANNING', 'revision' => 1,
        ]);
        $actor = Actor::query()->where('name', 'Administrator')->firstOrFail();
        $story = Story::query()->create([
            'project_id' => $project->id, 'sprint_id' => $sprint->id,
            'actor_id' => $actor->id, 'user_story_key' => 'US-01',
            'title' => 'Kelola pengajuan', 'sprint_purpose' => 'Pengajuan mudah dipantau oleh PO.',
            'acceptance_criteria' => "PO dapat membuat pengajuan.\nSistem menampilkan status pengajuan.",
            'scope_status' => 'NEEDS_REFINEMENT', 'revision' => 1,
            'estimate_points' => 5,
        ]);
        StoryEstimationRun::query()->create([
            'story_id' => $story->id, 'requested_by' => $owner->id,
            'story_revision' => 1, 'status' => 'COMPLETED',
            'consensus_points' => 5, 'consensus_method' => 'unanimous',
            'report_sent_at' => now(), 'completed_at' => now(),
        ]);
        $run = ExecutionRun::query()->create([
            'story_id' => $story->id, 'requested_by' => $owner->id, 'status' => $runStatus,
        ]);

        return [$owner, $project, $story, $run];
    }
}
