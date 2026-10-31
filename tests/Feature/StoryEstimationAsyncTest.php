<?php

namespace Tests\Feature;

use App\Jobs\AdvanceStoryEstimationJob;
use App\Jobs\EstimateStoryAgentJob;
use App\Models\Actor;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Story;
use App\Models\StoryEstimationRun;
use App\Models\User;
use App\Services\StoryEstimationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StoryEstimationAsyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_fibonacci_discussion_uses_two_rounds(): void
    {
        $this->assertSame(2, StoryEstimationService::MAX_DISCUSSION_ROUNDS);
    }

    public function test_store_claims_queued_run_dispatches_one_advance_job_without_inference(): void
    {
        Queue::fake();
        [$owner, $project, $story] = $this->makeStory();

        $response = $this->actingAs($owner)->post('/stories/'.$story->id.'/estimation-runs');

        $response->assertRedirect('/projects/'.$project->id.'/sprint-backlog')->assertSessionHas('status');
        $run = StoryEstimationRun::query()->firstOrFail();
        $this->assertSame('QUEUED', $run->status);
        Queue::assertPushed(AdvanceStoryEstimationJob::class, fn ($job) => $job->runId === $run->id);
    }

    public function test_store_claims_queued_run_dispatches_job_and_returns_json_for_progress_dialog(): void
    {
        Queue::fake();
        [$owner, $project, $story] = $this->makeStory();

        $response = $this->actingAs($owner)->withHeader('Accept', 'application/json')
            ->postJson('/stories/'.$story->id.'/estimation-runs');

        $run = StoryEstimationRun::query()->firstOrFail();
        $response->assertAccepted()->assertJsonPath('run_id', $run->id)->assertJsonPath('status', 'QUEUED');
        Queue::assertPushed(AdvanceStoryEstimationJob::class);
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
        Queue::assertPushed(AdvanceStoryEstimationJob::class, 1);
    }

    public function test_worker_failure_terminalizes_run_and_in_progress_agent(): void
    {
        [$owner, $project, $story] = $this->makeStory();
        $run = StoryEstimationRun::query()->create([
            'story_id' => $story->id, 'requested_by' => $owner->id,
            'story_revision' => $story->revision, 'status' => 'RUNNING',
        ]);
        $agentRun = $run->agentRuns()->create([
            'profile_slug' => 'backend-developer', 'round' => 3, 'status' => 'RUNNING', 'started_at' => now(),
        ]);

        $job = new EstimateStoryAgentJob($run->id, $agentRun->id);
        $job->failed(new \RuntimeException('worker timeout'));

        $this->assertSame('FAILED', $run->fresh()->status);
        $this->assertSame('WORKER_FAILED', $agentRun->fresh()->error_code);
        $this->assertSame('FAILED', $agentRun->fresh()->status);
        $this->assertNotNull($run->fresh()->completed_at);
    }

    public function test_each_agent_job_has_a_per_agent_timeout(): void
    {
        $job = new EstimateStoryAgentJob(1, 1);

        $this->assertSame(180, $job->timeout);
        $this->assertGreaterThan($job->timeout, config('queue.connections.database.retry_after'));
    }

    public function test_advance_dispatches_exactly_three_independent_agent_jobs_for_round_one(): void
    {
        Queue::fake();
        [$owner, $project, $story] = $this->makeStory();
        $run = StoryEstimationRun::query()->create([
            'story_id' => $story->id, 'requested_by' => $owner->id,
            'story_revision' => $story->revision, 'status' => 'QUEUED',
        ]);

        (new AdvanceStoryEstimationJob($run->id))->handle(app(StoryEstimationService::class));

        $this->assertSame('RUNNING', $run->fresh()->status);
        $this->assertSame(3, $run->agentRuns()->where('round', 1)->where('status', 'QUEUED')->count());
        Queue::assertPushed(EstimateStoryAgentJob::class, 3);
    }

    public function test_failed_agent_can_be_retried_without_restarting_successful_agents(): void
    {
        Queue::fake();
        [$owner, $project, $story] = $this->makeStory();
        $run = StoryEstimationRun::query()->create([
            'story_id' => $story->id, 'requested_by' => $owner->id,
            'story_revision' => $story->revision,
            'story_snapshot_hash' => hash('sha256', json_encode([
                'story_id' => $story->display_id, 'revision' => $story->revision,
                'actor' => $story->actor?->name, 'feature' => $story->title,
                'purpose' => $story->sprint_purpose, 'acceptance_criteria' => $story->acceptance_criteria,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'status' => 'FAILED',
        ]);
        $successful = $run->agentRuns()->create([
            'profile_slug' => 'backend-developer', 'round' => 1, 'status' => 'COMPLETED', 'estimate_points' => 3,
        ]);
        $failed = $run->agentRuns()->create([
            'profile_slug' => 'frontend-developer', 'round' => 1, 'status' => 'FAILED', 'error_code' => 'PROVIDER_TIMEOUT',
        ]);

        $this->actingAs($owner)->post('/stories/'.$story->id.'/estimation-runs/'.$run->id.'/agents/'.$failed->id.'/retry')
            ->assertRedirect('/projects/'.$project->id.'/sprint-backlog')->assertSessionHas('status');

        $this->assertSame('RUNNING', $run->fresh()->status);
        $this->assertSame('QUEUED', $failed->fresh()->status);
        $this->assertSame('COMPLETED', $successful->fresh()->status);
        Queue::assertPushed(EstimateStoryAgentJob::class, fn ($job) => $job->agentRunId === $failed->id);
    }

    public function test_one_agent_failure_does_not_cancel_round_before_other_agents_finish(): void
    {
        [$owner, $project, $story] = $this->makeStory();
        $run = StoryEstimationRun::query()->create([
            'story_id' => $story->id, 'requested_by' => $owner->id,
            'story_revision' => $story->revision, 'status' => 'RUNNING',
        ]);
        $failed = $run->agentRuns()->create(['profile_slug' => 'backend-developer', 'round' => 1, 'status' => 'RUNNING']);
        $run->agentRuns()->create(['profile_slug' => 'frontend-developer', 'round' => 1, 'status' => 'QUEUED']);
        $run->agentRuns()->create(['profile_slug' => 'qa-engineer', 'round' => 1, 'status' => 'QUEUED']);

        app(StoryEstimationService::class)->failAgent($failed, 'INVALID_JSON_SCHEMA');

        $this->assertSame('RUNNING', $run->fresh()->status);
        $this->assertSame('FAILED', $failed->fresh()->status);
    }

    public function test_status_endpoint_recovers_agent_without_heartbeat(): void
    {
        [$owner, $project, $story] = $this->makeStory();
        $run = StoryEstimationRun::query()->create([
            'story_id' => $story->id, 'requested_by' => $owner->id,
            'story_revision' => $story->revision, 'status' => 'RUNNING',
        ]);
        $agent = $run->agentRuns()->create([
            'profile_slug' => 'qa-engineer', 'round' => 1, 'status' => 'RUNNING',
            'started_at' => now()->subMinutes(6), 'heartbeat_at' => now()->subMinutes(6),
        ]);

        $this->actingAs($owner)->getJson('/stories/'.$story->id.'/estimation-runs/'.$run->id.'/status')->assertOk();

        $this->assertSame('FAILED', $run->fresh()->status);
        $this->assertSame('WORKER_STALE', $agent->fresh()->error_code);
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

    public function test_process_agent_logs_raw_answer_when_async_vote_fails_validation(): void
    {
        // Vote QA kini lewat gateway dengan model dari menu.
        config([
            'ai.cliproxy.agents_enabled' => true,
            'ai.cliproxy.api_key' => 'test-key',
            'ai.cliproxy.base_url' => 'http://127.0.0.1:8317',
        ]);
        $this->mock(\App\Services\AgentConfiguration::class, function (\Mockery\MockInterface $mock) {
            $mock->shouldReceive('current')->with('qa-engineer')->andReturn(['status' => 'verified', 'model' => 'qa-model']);
        });
        \Illuminate\Support\Facades\Http::fake([
            'http://127.0.0.1:8317/v1/models' => \Illuminate\Support\Facades\Http::response(['data' => [['id' => 'qa-model', 'owned_by' => 'openai']]]),
            'http://127.0.0.1:8317/v1/chat/completions' => \Illuminate\Support\Facades\Http::response(['choices' => [['message' => ['content' => 'Saya menilai story ini 5 poin.']]]]),
        ]);
        \Illuminate\Support\Facades\Log::spy();
        [$owner, $project, $story] = $this->makeStory();
        [$run] = app(StoryEstimationService::class)->claimRun($story, $owner);
        $run->forceFill(['status' => 'RUNNING'])->save();
        $entry = $run->agentRuns()->create(['profile_slug' => 'qa-engineer', 'round' => 1, 'status' => 'QUEUED']);
        app(StoryEstimationService::class)->processAgent($entry);
        $this->assertSame('FAILED', $entry->fresh()->status);
        $this->assertSame('INVALID_JSON_SCHEMA', $entry->fresh()->error_code);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->with('Story estimation vote failed validation', \Mockery::on(function (array $context) {
                return $context['profile_slug'] === 'qa-engineer'
                    && $context['error_code'] === 'INVALID_JSON_SCHEMA'
                    && str_contains($context['raw_excerpt'], 'Saya menilai story ini 5 poin.');
            }));
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

    public function test_store_recovers_stale_running_and_claims_new_run(): void
    {
        Queue::fake();
        [$owner, $project, $story] = $this->makeStory();
        $stale = StoryEstimationRun::query()->create([
            'story_id' => $story->id, 'requested_by' => $owner->id,
            'story_revision' => $story->revision, 'status' => 'RUNNING',
        ]);
        $stale->agentRuns()->create([
            'profile_slug' => 'backend-developer', 'round' => 1, 'status' => 'RUNNING',
            'started_at' => now()->subMinutes(6), 'heartbeat_at' => now()->subMinutes(6),
        ]);

        $this->actingAs($owner)->post('/stories/'.$story->id.'/estimation-runs')
            ->assertRedirect('/projects/'.$project->id.'/sprint-backlog')->assertSessionHas('status');

        // Run lama yang macet ditandai FAILED, lalu run baru diklaim.
        $this->assertSame('FAILED', $stale->fresh()->status);
        $new = StoryEstimationRun::query()->where('id', '!=', $stale->id)->firstOrFail();
        $this->assertSame('QUEUED', $new->status);
        Queue::assertPushed(AdvanceStoryEstimationJob::class, fn ($job) => $job->runId === $new->id);
    }

    public function test_store_keeps_genuinely_active_run_without_recreating(): void
    {
        Queue::fake();
        [$owner, $project, $story] = $this->makeStory();
        $active = StoryEstimationRun::query()->create([
            'story_id' => $story->id, 'requested_by' => $owner->id,
            'story_revision' => $story->revision, 'status' => 'RUNNING',
        ]);
        $active->agentRuns()->create([
            'profile_slug' => 'backend-developer', 'round' => 1, 'status' => 'RUNNING',
            'started_at' => now(), 'heartbeat_at' => now(),
        ]);

        $this->actingAs($owner)->post('/stories/'.$story->id.'/estimation-runs')
            ->assertRedirect('/projects/'.$project->id.'/sprint-backlog')->assertSessionHas('status');

        $this->assertSame('RUNNING', $active->fresh()->status);
        $this->assertSame(1, StoryEstimationRun::query()->count());
        Queue::assertNotPushed(AdvanceStoryEstimationJob::class);
    }

    public function test_discussion_page_recovers_stale_running_run(): void
    {
        [$owner, $project, $story] = $this->makeStory();
        $run = StoryEstimationRun::query()->create([
            'story_id' => $story->id, 'requested_by' => $owner->id,
            'story_revision' => $story->revision, 'status' => 'RUNNING',
        ]);
        $run->agentRuns()->create([
            'profile_slug' => 'qa-engineer', 'round' => 1, 'status' => 'RUNNING',
            'started_at' => now()->subMinutes(6), 'heartbeat_at' => now()->subMinutes(6),
        ]);

        $this->actingAs($owner)->get('/stories/'.$story->id.'/discussion')->assertOk();

        $this->assertSame('FAILED', $run->fresh()->status);
    }
}
