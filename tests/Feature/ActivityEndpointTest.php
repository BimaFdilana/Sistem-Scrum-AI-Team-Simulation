<?php

namespace Tests\Feature;

use App\Models\ExecutionRun;
use App\Models\Project;
use App\Models\Story;
use App\Models\StoryEstimationRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(string $status, ?string $updated = null): ExecutionRun
    {
        $project = Project::query()->firstOrCreate(['name' => 'AE'], ['project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'BASELINE_READY']);
        $story = Story::query()->create(['project_id' => $project->id, 'title' => 'Story '.$status, 'estimate_points' => 3]);
        $run = ExecutionRun::query()->create(['story_id' => $story->id, 'requested_by' => User::factory()->create()->id, 'status' => $status]);
        if ($updated) {
            ExecutionRun::query()->whereKey($run->id)->update(['updated_at' => $updated]);
        }

        return $run;
    }

    public function test_guest_cannot_read_activity(): void
    {
        $this->get(route('activity.active'))->assertRedirect();
    }

    public function test_lists_running_attention_and_recent_done_runs_but_not_old_ones(): void
    {
        $running = $this->makeRun('EXECUTING');
        $this->makeRun('NEEDS_PO');
        $this->makeRun('COMPLETED');
        $this->makeRun('COMPLETED', now()->subHours(3)->toDateTimeString());

        $response = $this->actingAs(User::factory()->create())->getJson(route('activity.active'))->assertOk();

        $response->assertJsonCount(3, 'runs')
            ->assertJsonPath('running', 1)
            ->assertJsonPath('attention', 1);
        $this->assertContains($running->id, collect($response->json('runs'))->pluck('id')->all());
    }

    public function test_needs_po_run_exposes_resume_action_and_stage(): void
    {
        $run = $this->makeRun('NEEDS_PO');

        $json = $this->actingAs(User::factory()->create())->getJson(route('activity.active'))->json('runs.0');

        $this->assertSame('attention', $json['state']);
        $this->assertSame('resume', $json['actions'][0]['key']);
        $this->assertSame(route('stories.executions.resume', [$run->story, $run]), $json['actions'][0]['url']);
        $this->assertCount(5, $json['stage']['steps']);
    }

    public function test_unchanged_payload_returns_304_with_etag(): void
    {
        $this->makeRun('EXECUTING');
        $user = User::factory()->create();

        $first = $this->actingAs($user)->getJson(route('activity.active'))->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->actingAs($user)->getJson(route('activity.active'), ['If-None-Match' => $etag])->assertStatus(304);
    }

    public function test_empty_when_nothing_is_running(): void
    {
        $this->actingAs(User::factory()->create())->getJson(route('activity.active'))
            ->assertOk()->assertJsonCount(0, 'runs');
    }

    public function test_polling_does_not_exhaust_the_throttle_of_other_routes(): void
    {
        $run = $this->makeRun('NEEDS_PO');
        $user = User::factory()->create();

        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($user)->getJson(route('activity.active'))->assertOk();
        }

        $this->actingAs($user)->post(route('stories.executions.resume', [$run->story, $run]))
            ->assertRedirect();
        $this->assertSame('EXECUTING', $run->fresh()->status);
    }

    private function makeEstimation(User $owner, string $status, array $agents = []): StoryEstimationRun
    {
        $project = Project::query()->firstOrCreate(['name' => 'AE'], ['project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'BASELINE_READY']);
        $story = Story::query()->create(['project_id' => $project->id, 'title' => 'Fitur estimasi', 'estimate_points' => 3, 'revision' => 1]);
        $run = StoryEstimationRun::query()->create([
            'story_id' => $story->id, 'requested_by' => $owner->id, 'story_revision' => 1, 'status' => $status,
            'consensus_points' => $status === 'COMPLETED' ? 5 : null,
        ]);
        foreach ($agents as $slug => $agentStatus) {
            $run->agentRuns()->create(['profile_slug' => $slug, 'round' => 1, 'status' => $agentStatus]);
        }

        return $run;
    }

    public function test_running_fibonacci_discussion_is_listed_with_agents_and_progress(): void
    {
        $user = User::factory()->create();
        $run = $this->makeEstimation($user, 'RUNNING', ['backend-developer' => 'COMPLETED', 'frontend-developer' => 'RUNNING', 'qa-engineer' => 'QUEUED']);

        $json = $this->actingAs($user)->getJson(route('activity.active'))->assertOk();

        $json->assertJsonPath('running', 1)->assertJsonCount(1, 'estimations');
        $estimation = $json->json('estimations.0');
        $this->assertSame('estimation', $estimation['kind']);
        $this->assertSame('Putaran 1/2 · Frontend menjawab', $estimation['headline']);
        $this->assertSame(['COMPLETED', 'RUNNING', 'QUEUED'], array_column($estimation['agents'], 'status'));
        $this->assertSame(route('stories.discussion.show', $run->story), $estimation['detail_url']);
    }

    public function test_completed_discussion_shows_the_final_points_and_failed_one_offers_retry(): void
    {
        $user = User::factory()->create();
        $this->makeEstimation($user, 'COMPLETED');
        $failed = $this->makeEstimation($user, 'FAILED', ['backend-developer' => 'FAILED']);

        $estimations = collect($this->actingAs($user)->getJson(route('activity.active'))->json('estimations'));

        $this->assertStringContainsString('Selesai · 5 poin', $estimations->firstWhere('state', 'done')['headline']);
        $attention = $estimations->firstWhere('state', 'attention');
        $this->assertSame('retry', $attention['actions'][0]['key']);
        $this->assertSame(route('stories.estimations.retry', [$failed->story, $failed]), $attention['actions'][0]['url']);
    }

    public function test_other_users_discussions_are_not_listed(): void
    {
        $owner = User::factory()->create();
        $this->makeEstimation($owner, 'RUNNING', ['backend-developer' => 'RUNNING']);

        $this->actingAs(User::factory()->create())->getJson(route('activity.active'))->assertJsonCount(0, 'estimations');
    }
}
