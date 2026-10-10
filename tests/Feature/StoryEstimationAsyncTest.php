<?php

namespace Tests\Feature;

use App\Jobs\RunStoryEstimationJob;
use App\Models\Actor;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Story;
use App\Models\StoryEstimationRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StoryEstimationAsyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_claims_running_run_dispatches_job_and_returns_without_inference(): void
    {
        Queue::fake();
        [$owner, $project, $story] = $this->makeStory();

        $response = $this->actingAs($owner)->post('/stories/'.$story->id.'/estimation-runs');

        $response->assertRedirect('/projects/'.$project->id.'/sprint-backlog')->assertSessionHas('status');
        $run = StoryEstimationRun::query()->firstOrFail();
        $this->assertSame('RUNNING', $run->status);
        Queue::assertPushed(RunStoryEstimationJob::class, fn ($job) => $job->runId === $run->id && $job->requesterId === $owner->id);
    }

    public function test_store_claims_running_run_dispatches_job_and_returns_json_for_progress_dialog(): void
    {
        Queue::fake();
        [$owner, $project, $story] = $this->makeStory();

        $response = $this->actingAs($owner)->withHeader('Accept', 'application/json')
            ->postJson('/stories/'.$story->id.'/estimation-runs');

        $run = StoryEstimationRun::query()->firstOrFail();
        $response->assertAccepted()->assertJsonPath('run_id', $run->id)->assertJsonPath('status', 'RUNNING');
        Queue::assertPushed(RunStoryEstimationJob::class);
    }

    public function test_status_endpoint_returns_requester_scoped_progress_and_failure(): void
    {
        [$owner, $project, $story] = $this->makeStory();
        $run = StoryEstimationRun::query()->create([
            'story_id' => $story->id, 'requested_by' => $owner->id,
            'story_revision' => $story->revision, 'status' => 'RUNNING',
        ]);
        $run->agentRuns()->createMany([
            ['profile_slug' => 'backend-developer', 'round' => 1, 'status' => 'COMPLETED', 'completed_at' => now()],
            ['profile_slug' => 'frontend-developer', 'round' => 1, 'status' => 'FAILED', 'error_code' => 'PROVIDER_TIMEOUT', 'completed_at' => now()],
        ]);

        $this->actingAs($owner)->getJson('/stories/'.$story->id.'/estimation-runs/'.$run->id.'/status')
            ->assertOk()
            ->assertJsonPath('status', 'RUNNING')
            ->assertJsonPath('round', 1)
            ->assertJsonPath('completed_agents', 1)
            ->assertJsonPath('failed_agent.profile', 'frontend-developer')
            ->assertJsonPath('failed_agent.error_code', 'PROVIDER_TIMEOUT');
    }

    public function test_status_endpoint_hides_another_requesters_run(): void
    {
        [$owner, $project, $story] = $this->makeStory();
        $run = StoryEstimationRun::query()->create([
            'story_id' => $story->id, 'requested_by' => $owner->id,
            'story_revision' => $story->revision, 'status' => 'RUNNING',
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson('/stories/'.$story->id.'/estimation-runs/'.$run->id.'/status')
            ->assertForbidden();
    }

    public function test_retry_creates_one_new_run_only_for_terminal_retryable_status(): void
    {
        Queue::fake();
        [$owner, $project, $story] = $this->makeStory();
        $failed = StoryEstimationRun::query()->create([
            'story_id' => $story->id, 'requested_by' => $owner->id,
            'story_revision' => $story->revision, 'status' => 'FAILED',
        ]);

        $this->actingAs($owner)->post('/stories/'.$story->id.'/estimation-runs/'.$failed->id.'/retry')
            ->assertRedirect('/projects/'.$project->id.'/sprint-backlog')->assertSessionHas('status');

        $this->assertDatabaseCount('story_estimation_runs', 2);
        $this->assertDatabaseHas('story_estimation_runs', ['id' => $failed->id, 'status' => 'FAILED']);
        Queue::assertPushed(RunStoryEstimationJob::class, 1);
    }

    public function test_retry_never_creates_a_second_active_run(): void
    {
        Queue::fake();
        [$owner, $project, $story] = $this->makeStory();
        $active = StoryEstimationRun::query()->create([
            'story_id' => $story->id, 'requested_by' => $owner->id,
            'story_revision' => $story->revision, 'status' => 'RUNNING',
        ]);
        $failed = StoryEstimationRun::query()->create([
            'story_id' => $story->id, 'requested_by' => $owner->id,
            'story_revision' => $story->revision, 'status' => 'FAILED',
        ]);

        $this->actingAs($owner)->post('/stories/'.$story->id.'/estimation-runs/'.$failed->id.'/retry')
            ->assertRedirect('/projects/'.$project->id.'/sprint-backlog')->assertSessionHasErrors('estimation');

        $this->assertSame(2, StoryEstimationRun::query()->count());
        $this->assertSame($active->id, StoryEstimationRun::query()->where('status', 'RUNNING')->value('id'));
        Queue::assertNothingPushed();
    }

    private function makeStory(): array
    {
        $owner = User::factory()->create();
        $project = Project::query()->create(['name' => 'Async Workspace', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'SETUP_REQUIRED']);
        $sprint = Sprint::query()->create(['project_id' => $project->id, 'backlog_id' => $project->backlog->id, 'name' => 'Sprint 1', 'status' => 'PLANNING', 'revision' => 1]);
        $actor = Actor::query()->where('name', 'Administrator')->firstOrFail();
        $story = Story::query()->create([
            'project_id' => $project->id, 'sprint_id' => $sprint->id, 'actor_id' => $actor->id, 'user_story_key' => 'US-01',
            'title' => 'Kelola pengajuan', 'sprint_purpose' => 'Pengajuan mudah dipantau.', 'acceptance_criteria' => 'PO dapat melihat status.',
            'scope_status' => 'NEEDS_REFINEMENT', 'revision' => 1,
        ]);

        return [$owner, $project, $story];
    }
}
