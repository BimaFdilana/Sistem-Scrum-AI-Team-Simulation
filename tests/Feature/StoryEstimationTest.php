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
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StoryEstimationTest extends TestCase
{
    use RefreshDatabase;

    public function test_p_o_can_request_independent_fibonacci_estimates_and_scrum_master_posts_the_summary(): void
    {
        $this->configureEstimationProfiles();
        Http::fake(function (Request $request) {
            $content = $request['messages'][1]['content'] ?? '';
            if (str_contains($request->url(), 'scrum-master.test')) {
                return Http::response(['choices' => [['message' => ['content' => 'Scrum Master: hasil estimasi telah dirangkum.']]]]);
            }

            $points = match (true) {
                str_contains($request->url(), 'backend.test') => str_contains($content, 'PUTARAN 2') ? 5 : 3,
                str_contains($request->url(), 'frontend.test') => str_contains($content, 'PUTARAN 2') ? 5 : 5,
                str_contains($request->url(), 'qa.test') => str_contains($content, 'PUTARAN 2') ? 8 : 8,
                default => null,
            };

            return $points
                ? Http::response(['choices' => [['message' => ['content' => json_encode(['estimate_points' => $points, 'rationale' => 'Estimasi berdasarkan cakupan dan kriteria yang diberikan.'])]]]])
                : Http::response([], 404);
        });
        [$owner, $project, $story] = $this->makeStory();

        $this->actingAs($owner)
            ->post('/stories/'.$story->id.'/estimation-runs')
            ->assertRedirect('/projects/'.$project->id.'/sprint-backlog')
            ->assertSessionHas('status');

        $this->assertDatabaseHas('story_estimation_runs', [
            'story_id' => $story->id,
            'story_revision' => 1,
            'status' => 'COMPLETED',
        ]);
        $this->assertDatabaseCount('story_estimation_agent_runs', 6);
        $this->assertDatabaseHas('chat_conversations', [
            'user_id' => $owner->id,
            'agent_profile' => 'scrum-master',
        ]);
        $chatSummary = \App\Models\ChatMessage::query()->where('role', 'assistant')->value('content');
        $this->assertStringContainsString('Scrum Master: hasil estimasi telah dirangkum.', $chatSummary);
        $this->assertStringContainsString('Backend Developer: 3 → 5', $chatSummary);
        $this->assertNull($story->fresh()->estimate_points);
        Http::assertSentCount(7);

        $this->actingAs($owner)->get('/agents/backend-developer/activity')
            ->assertOk()->assertSee('REFINEMENT FIBONACCI')->assertSee('US-01')
            ->assertSee('Putaran 1')->assertSee('Putaran 2');
    }

    public function test_p_o_can_set_only_a_fibonacci_value_from_a_completed_current_run(): void
    {
        $this->configureEstimationProfiles();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'scrum-master.test')) {
                return Http::response(['choices' => [['message' => ['content' => 'Ringkasan Scrum Master.']]]]);
            }

            return Http::response(['choices' => [['message' => ['content' => json_encode(['estimate_points' => 5, 'rationale' => 'Cakupan sedang.'])]]]]);
        });
        [$owner, $project, $story] = $this->makeStory();
        $this->actingAs($owner)->post('/stories/'.$story->id.'/estimation-runs');
        $runId = (int) \DB::table('story_estimation_runs')->where('story_id', $story->id)->value('id');

        $this->actingAs($owner)->post('/stories/'.$story->id.'/estimation-runs/'.$runId.'/approve', ['estimate_points' => 5])
            ->assertRedirect('/projects/'.$project->id.'/sprint-backlog');

        $this->assertSame(5, $story->fresh()->estimate_points);
        $this->assertDatabaseHas('story_estimation_runs', ['id' => $runId, 'approved_points' => 5, 'approved_by' => $owner->id]);
    }

    public function test_invalid_or_stale_estimation_cannot_be_approved(): void
    {
        [$owner, $project, $story] = $this->makeStory();
        $runId = \DB::table('story_estimation_runs')->insertGetId([
            'story_id' => $story->id,
            'requested_by' => $owner->id,
            'story_revision' => 1,
            'status' => 'COMPLETED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($owner)->post('/stories/'.$story->id.'/estimation-runs/'.$runId.'/approve', ['estimate_points' => 4])
            ->assertSessionHasErrors('estimate_points');

        $story->forceFill(['revision' => 2])->save();
        $this->actingAs($owner)->post('/stories/'.$story->id.'/estimation-runs/'.$runId.'/approve', ['estimate_points' => 5])
            ->assertRedirect('/projects/'.$project->id.'/sprint-backlog')->assertSessionHasErrors('estimation');
        $this->assertNull($story->fresh()->estimate_points);
    }

    public function test_story_backlog_displays_the_estimation_action_and_current_point_value(): void
    {
        [$owner, $project, $story] = $this->makeStory();
        $story->forceFill(['estimate_points' => 8])->save();
        $run = StoryEstimationRun::query()->create([
            'story_id' => $story->id,
            'requested_by' => $owner->id,
            'story_revision' => $story->revision,
            'status' => 'COMPLETED',
            'summary' => 'Perbedaan estimasi dibahas.',
            'approved_points' => 8,
            'approved_by' => $owner->id,
            'approved_at' => now(),
            'completed_at' => now(),
        ]);
        foreach (['backend-developer', 'frontend-developer', 'qa-engineer'] as $profile) {
            foreach ([1, 2] as $round) {
                StoryEstimationAgentRun::query()->create([
                    'story_estimation_run_id' => $run->id,
                    'profile_slug' => $profile,
                    'round' => $round,
                    'status' => 'COMPLETED',
                    'estimate_points' => 5,
                    'rationale' => 'Diperiksa terhadap kriteria.',
                ]);
            }
        }

        $this->actingAs($owner)->get('/projects/'.$project->id.'/sprint-backlog')
            ->assertOk()->assertSee('Estimasi Fibonacci via Scrum Master')
            ->assertSee('8 poin Fibonacci')
            ->assertSee('Buka hasil diskusi di chat Scrum Master');
    }

    private function configureEstimationProfiles(): void
    {
        config([
            'services.hermes.base_url' => 'http://scrum-master.test/v1',
            'services.hermes.api_key' => 'sm-test-key',
            'services.hermes.model' => 'scrum-master',
            'services.agent_profiles.backend-developer.base_url' => 'http://backend.test/v1',
            'services.agent_profiles.backend-developer.api_key' => 'backend-test-key',
            'services.agent_profiles.backend-developer.model' => 'backend-developer',
            'services.agent_profiles.frontend-developer.base_url' => 'http://frontend.test/v1',
            'services.agent_profiles.frontend-developer.api_key' => 'frontend-test-key',
            'services.agent_profiles.frontend-developer.model' => 'frontend-developer',
            'services.agent_profiles.qa-engineer.base_url' => 'http://qa.test/v1',
            'services.agent_profiles.qa-engineer.api_key' => 'qa-test-key',
            'services.agent_profiles.qa-engineer.model' => 'qa-engineer',
        ]);
    }

    /** @return array{User, Project, Story} */
    private function makeStory(): array
    {
        $owner = User::factory()->create();
        $project = Project::query()->create([
            'name' => 'Budget Workspace',
            'project_type' => 'NEW_PROJECT',
            'stack' => 'Laravel',
            'status' => 'SETUP_REQUIRED',
        ]);
        $backlog = $project->backlog;
        $sprint = Sprint::query()->create([
            'project_id' => $project->id,
            'backlog_id' => $backlog->id,
            'name' => 'Sprint 1',
            'status' => 'PLANNING',
            'revision' => 1,
        ]);
        $actor = Actor::query()->where('name', 'Administrator')->firstOrFail();
        $story = Story::query()->create([
            'project_id' => $project->id,
            'sprint_id' => $sprint->id,
            'actor_id' => $actor->id,
            'user_story_key' => 'US-01',
            'title' => 'Kelola pengajuan',
            'sprint_purpose' => 'Pengajuan mudah dipantau oleh PO.',
            'acceptance_criteria' => "PO dapat membuat pengajuan.\nSistem menampilkan status pengajuan.",
            'scope_status' => 'NEEDS_REFINEMENT',
            'revision' => 1,
        ]);

        return [$owner, $project, $story];
    }
}
