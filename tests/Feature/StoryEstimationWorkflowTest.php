<?php

namespace Tests\Feature;

use App\Models\Actor;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Story;
use App\Models\StoryEstimationAgentRun;
use App\Models\StoryEstimationRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoryEstimationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_p_o_cannot_choose_a_different_final_point_for_a_completed_run(): void
    {
        [$owner, $project, $story] = $this->makeStory();
        $run = StoryEstimationRun::query()->create([
            'story_id' => $story->id,
            'requested_by' => $owner->id,
            'story_revision' => $story->revision,
            'status' => 'COMPLETED',
            'summary' => 'Laporan kesepakatan terkirim.',
            'completed_at' => now(),
        ]);
        foreach (['backend-developer', 'frontend-developer', 'qa-engineer'] as $slug) {
            foreach ([1, 2] as $round) {
                StoryEstimationAgentRun::query()->create([
                    'story_estimation_run_id' => $run->id,
                    'profile_slug' => $slug,
                    'round' => $round,
                    'status' => 'COMPLETED',
                    'estimate_points' => 5,
                    'rationale' => 'Sepakat setelah membaca pendapat tim.',
                ]);
            }
        }

        $this->actingAs($owner)->post('/stories/'.$story->id.'/estimation-runs/'.$run->id.'/approve', ['estimate_points' => 8])
            ->assertNotFound();
        $this->assertNull($story->fresh()->estimate_points);
        $this->assertNull($run->fresh()->approved_points);
    }

    public function test_team_discussion_is_read_only_and_accessible_from_each_story_in_backlog(): void
    {
        [$owner, $project, $story] = $this->makeStory();
        $this->actingAs($owner)->get('/projects/'.$project->id.'/sprint-backlog')
            ->assertOk()->assertSee('Lihat Diskusi Tim')->assertSee('/stories/'.$story->id.'/discussion', false)
            ->assertDontSee('Poin final pilihan PO');
        $this->get('/stories/'.$story->id.'/discussion')->assertOk()->assertSee('Diskusi Fibonacci')->assertSee('Eksekusi & Revisi QA')
            ->assertDontSee('name="content"', false);
        $this->post('/stories/'.$story->id.'/discussion', ['content' => 'Saya pilih 13'])->assertStatus(405);
        auth()->logout();
        $this->get('/stories/'.$story->id.'/discussion')->assertRedirect('/login');
    }

    public function test_stale_running_discussion_can_be_cancelled_by_requester_only(): void
    {
        [$owner, $project, $story] = $this->makeStory();
        $run = StoryEstimationRun::query()->create([
            'story_id' => $story->id,
            'requested_by' => $owner->id,
            'story_revision' => $story->revision,
            'status' => 'RUNNING',
        ]);
        $run->forceFill(['created_at' => now()->subMinutes(31)])->save();
        $other = User::factory()->create();
        $this->actingAs($other)->post('/stories/'.$story->id.'/estimation-runs/'.$run->id.'/cancel')->assertForbidden();
        $this->actingAs($owner)->post('/stories/'.$story->id.'/estimation-runs/'.$run->id.'/cancel')
            ->assertRedirect('/projects/'.$project->id.'/sprint-backlog');
        $this->assertSame('CANCELLED', $run->fresh()->status);
        $this->assertNull($story->fresh()->estimate_points);
    }

    public function test_discussion_hides_another_requesters_private_agent_messages(): void
    {
        [$owner, $project, $story] = $this->makeStory();
        $run = StoryEstimationRun::query()->create([
            'story_id' => $story->id, 'requested_by' => $owner->id,
            'story_revision' => $story->revision, 'status' => 'NO_CONSENSUS',
        ]);
        $run->messages()->create([
            'story_revision' => $story->revision, 'round' => 1,
            'sender_slug' => 'qa-engineer', 'recipient_slug' => 'scrum-master',
            'content' => 'pesan-run-pribadi-123', 'estimate_points' => 3,
        ]);
        $this->actingAs($owner)->get('/stories/'.$story->id.'/discussion')->assertOk()->assertSee('pesan-run-pribadi-123');
        $this->actingAs(User::factory()->create())->get('/stories/'.$story->id.'/discussion')
            ->assertOk()->assertDontSee('pesan-run-pribadi-123');
    }

    private function makeStory(): array
    {
        $owner = User::factory()->create();
        $project = Project::query()->create([
            'name' => 'Contoh Laravel', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'SETUP_REQUIRED',
        ]);
        $sprint = Sprint::query()->create([
            'project_id' => $project->id, 'backlog_id' => $project->backlog->id,
            'name' => 'Sprint 1', 'status' => 'PLANNING', 'revision' => 1,
        ]);
        $actor = Actor::query()->where('name', 'Administrator')->firstOrFail();
        $story = Story::query()->create([
            'project_id' => $project->id, 'sprint_id' => $sprint->id,
            'actor_id' => $actor->id, 'user_story_key' => 'US-01',
            'title' => 'Kelola pengajuan', 'sprint_purpose' => 'Bantu PO memantau',
            'acceptance_criteria' => 'PO dapat melihat status.', 'scope_status' => 'NEEDS_REFINEMENT', 'revision' => 1,
        ]);

        return [$owner, $project, $story];
    }
}
