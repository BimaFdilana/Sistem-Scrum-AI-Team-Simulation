<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Story;
use App\Models\StoryEstimationRun;
use App\Models\User;
use App\Services\AgentProfileClient;
use App\Services\HermesClient;
use App\Services\StoryEstimationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StoryEstimationConsensusCoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_unique_index_blocks_active_duplicate_but_allows_retry_after_failure(): void
    {
        [$story, $owner] = $this->story();
        $failed = StoryEstimationRun::query()->create([
            'story_id' => $story->id, 'requested_by' => $owner->id,
            'story_revision' => 1, 'status' => 'FAILED',
        ]);
        $running = StoryEstimationRun::query()->create([
            'story_id' => $story->id, 'requested_by' => $owner->id,
            'story_revision' => 1, 'status' => 'RUNNING',
        ]);
        try {
            StoryEstimationRun::query()->create([
                'story_id' => $story->id, 'requested_by' => $owner->id,
                'story_revision' => 1, 'status' => 'REPORT_PENDING',
            ]);
            $this->fail('An active duplicate must violate the partial unique index.');
        } catch (QueryException $e) {
            $this->assertSame('23000', $e->getCode());
        }
        $running->update(['status' => 'NO_CONSENSUS']);
        $retry = StoryEstimationRun::query()->create([
            'story_id' => $story->id, 'requested_by' => $owner->id,
            'story_revision' => 1, 'status' => 'RUNNING',
        ]);
        $this->assertNotSame($failed->id, $retry->id);
    }

    public function test_only_requester_can_cancel_stale_running_run_and_retry(): void
    {
        [$story, $owner] = $this->story();
        $run = StoryEstimationRun::query()->create([
            'story_id' => $story->id, 'requested_by' => $owner->id,
            'story_revision' => 1, 'status' => 'RUNNING',
        ]);
        $service = app(StoryEstimationService::class);
        $this->expectException(\RuntimeException::class);
        $service->cancelStaleRun($run, User::factory()->create());
    }

    public function test_cancel_rejects_fresh_or_wrong_revision_and_allows_recovery_after_30_minutes(): void
    {
        [$story, $owner] = $this->story();
        $run = StoryEstimationRun::query()->create([
            'story_id' => $story->id, 'requested_by' => $owner->id,
            'story_revision' => 1, 'status' => 'RUNNING',
        ]);
        $service = app(StoryEstimationService::class);
        try {
            $service->cancelStaleRun($run, $owner);
            $this->fail('Fresh run must not be cancelled.');
        } catch (\RuntimeException) {
            $this->assertSame('RUNNING', $run->fresh()->status);
        }
        $run->forceFill(['created_at' => now()->subMinutes(31)])->save();
        $story->forceFill(['revision' => 2])->save();
        try {
            $service->cancelStaleRun($run, $owner);
            $this->fail('Different revision must not be cancelled.');
        } catch (\RuntimeException) {
            $this->assertSame('RUNNING', $run->fresh()->status);
        }
        $story->forceFill(['revision' => 1])->save();
        $this->assertSame('CANCELLED', $service->cancelStaleRun($run, $owner)->status);

        $agents = Mockery::mock(AgentProfileClient::class);
        $agents->shouldReceive('sendMessage')->times(9)->andReturnUsing(fn ($slug, $prompt) => str_contains($prompt, 'PUTARAN 1')
            ? '{"estimate_points":5,"rationale":"Alasan"}'
            : '{"estimate_points":5,"rationale":"Alasan","response":"Belum setuju","explicit_agree":false}');
        $this->app->instance(AgentProfileClient::class, $agents);
        $retry = app(StoryEstimationService::class)->estimate($story, $owner);
        $this->assertNotSame($run->id, $retry->id);
        $this->assertSame('CANCELLED', $run->fresh()->status);
    }

    public function test_cancelled_worker_cannot_resume_or_overwrite_cancelled_status(): void
    {
        [$story, $owner] = $this->story();
        $agents = Mockery::mock(AgentProfileClient::class);
        $agents->shouldReceive('sendMessage')->once()->andReturnUsing(function () use ($story, $owner, &$service) {
            $run = StoryEstimationRun::query()->where('story_id', $story->id)->firstOrFail();
            DB::table('story_estimation_runs')->where('id', $run->id)->update(['created_at' => now()->subMinutes(31)]);
            $service->cancelStaleRun($run, $owner);

            return '{"estimate_points":5,"rationale":"Terlambat"}';
        });
        $this->app->instance(AgentProfileClient::class, $agents);
        $service = app(StoryEstimationService::class);
        $run = $service->estimate($story, $owner);
        $this->assertSame('CANCELLED', $run->status);
        $this->assertNull($story->fresh()->estimate_points);
        $this->assertSame(0, $run->messages()->count());
    }

    public function test_sprint_transition_during_specialist_inference_stales_run_without_point(): void
    {
        [$story, $owner] = $this->story();
        $agents = Mockery::mock(AgentProfileClient::class);
        $agents->shouldReceive('sendMessage')->once()->andReturnUsing(function () use ($story) {
            $story->sprint->update(['status' => 'ACTIVE']);

            return '{"estimate_points":5,"rationale":"Terlambat"}';
        });
        $this->app->instance(AgentProfileClient::class, $agents);
        $run = app(StoryEstimationService::class)->estimate($story, $owner);
        $this->assertSame('STALE', $run->status);
        $this->assertNull($story->fresh()->estimate_points);
        $this->assertSame(0, $run->messages()->count());
    }

    public function test_initial_estimate_rejects_nonplanning_sprint_without_creating_run(): void
    {
        [$story, $owner] = $this->story();
        $story->sprint->update(['status' => 'ACTIVE']);
        try {
            app(StoryEstimationService::class)->estimate($story, $owner);
            $this->fail('Initial estimate must reject a non-planning sprint.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame(0, StoryEstimationRun::query()->count());
        $this->assertNull($story->fresh()->estimate_points);
    }

    public function test_sprint_transition_during_scrum_master_report_rolls_back_point_and_report(): void
    {
        [$story, $owner] = $this->story();
        $agents = Mockery::mock(AgentProfileClient::class);
        $agents->shouldReceive('sendMessage')->times(6)->andReturnUsing(function ($slug, $prompt) {
            $round = str_contains($prompt, 'PUTARAN 1') ? 1 : 2;

            return json_encode(['estimate_points' => 5, 'rationale' => 'Ukuran sah']
                + ($round === 2 ? ['response' => 'Setuju semua.', 'explicit_agree' => true] : []));
        });
        $this->app->instance(AgentProfileClient::class, $agents);
        $sm = Mockery::mock(HermesClient::class);
        $sm->shouldReceive('sendMessage')->once()->andReturnUsing(function () use ($story) {
            $story->sprint->update(['status' => 'ACTIVE']);

            return 'Laporan sah.';
        });
        $this->app->instance(HermesClient::class, $sm);
        $run = app(StoryEstimationService::class)->estimate($story, $owner);
        $this->assertNotSame('COMPLETED', $run->status);
        $this->assertNull($story->fresh()->estimate_points);
        $this->assertNull($run->report_sent_at);
        $this->assertSame(0, ChatMessage::query()->count());
    }

    public function test_existing_running_run_without_agent_entries_is_not_dispatched_again(): void
    {
        [$story, $owner] = $this->story();
        $run = StoryEstimationRun::query()->create([
            'story_id' => $story->id, 'requested_by' => $owner->id,
            'story_revision' => 1, 'status' => 'RUNNING',
        ]);
        $agents = Mockery::mock(AgentProfileClient::class);
        $agents->shouldNotReceive('sendMessage');
        $this->app->instance(AgentProfileClient::class, $agents);
        $same = app(StoryEstimationService::class)->estimate($story, $owner);
        $this->assertSame($run->id, $same->id);
        $this->assertSame('RUNNING', $same->status);
        $this->assertSame(0, $same->agentRuns()->count());
    }

    public function test_insert_collision_without_visible_winner_yields_conflict_not_server_error(): void
    {
        [$story, $owner] = $this->story();
        $agents = Mockery::mock(AgentProfileClient::class);
        $agents->shouldNotReceive('sendMessage');
        $this->app->instance(AgentProfileClient::class, $agents);
        $injected = false;
        StoryEstimationRun::creating(function (StoryEstimationRun $run) use (&$injected): void {
            if (! $injected) {
                $injected = true;
                DB::table('story_estimation_runs')->insert([
                    'story_id' => $run->story_id, 'requested_by' => $run->requested_by,
                    'story_revision' => $run->story_revision, 'status' => 'RUNNING',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
        try {
            app(StoryEstimationService::class)->estimate($story, $owner);
            $this->fail('Rolled-back contender requires a controlled conflict.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        } finally {
            StoryEstimationRun::flushEventListeners();
        }
        $this->assertTrue($injected);
        $this->assertSame(0, StoryEstimationRun::query()->count());
    }

    public function test_cancel_at_consensus_transition_cannot_resurrect_run_or_write_final_point(): void
    {
        [$story, $owner] = $this->story();
        $agents = Mockery::mock(AgentProfileClient::class);
        $agents->shouldReceive('sendMessage')->times(6)->andReturnUsing(function ($slug, $prompt) {
            $round = str_contains($prompt, 'PUTARAN 1') ? 1 : 2;

            return json_encode(['estimate_points' => 5, 'rationale' => 'Ukuran sah']
                + ($round === 2 ? ['response' => 'Setuju semua.', 'explicit_agree' => true] : []));
        });
        $this->app->instance(AgentProfileClient::class, $agents);
        $sm = Mockery::mock(HermesClient::class);
        $sm->shouldNotReceive('sendMessage');
        $this->app->instance(HermesClient::class, $sm);
        $cancelled = false;
        DB::listen(function ($query) use (&$cancelled, $story): void {
            if (! $cancelled && str_contains($query->sql, 'select "status" from "story_estimation_runs"')
                && DB::table('story_estimation_messages')->count() === 18) {
                $cancelled = true;
                DB::table('story_estimation_runs')->where('story_id', $story->id)->update(['status' => 'CANCELLED']);
            }
        });
        $run = app(StoryEstimationService::class)->estimate($story, $owner);
        $this->assertTrue($cancelled);
        $this->assertSame('CANCELLED', $run->status);
        $this->assertNull($story->fresh()->estimate_points);
    }

    public function test_report_retry_after_sprint_transition_never_assigns_points(): void
    {
        [$story, $owner] = $this->story();
        $agents = Mockery::mock(AgentProfileClient::class);
        $agents->shouldReceive('sendMessage')->times(6)->andReturnUsing(function ($slug, $prompt) {
            $round = str_contains($prompt, 'PUTARAN 1') ? 1 : 2;

            return json_encode(['estimate_points' => 5, 'rationale' => 'Ukuran sah']
                + ($round === 2 ? ['response' => 'Setuju semua.', 'explicit_agree' => true] : []));
        });
        $this->app->instance(AgentProfileClient::class, $agents);
        $sm = Mockery::mock(HermesClient::class);
        $sm->shouldReceive('sendMessage')->once()->andReturn('');
        $this->app->instance(HermesClient::class, $sm);
        $service = app(StoryEstimationService::class);
        $run = $service->estimate($story, $owner);
        $this->assertSame('REPORT_PENDING', $run->status);
        $story->sprint->update(['status' => 'ACTIVE']);
        $retried = $service->report($run, $owner);
        $this->assertSame('STALE', $retried->status);
        $this->assertNull($story->fresh()->estimate_points);
        $this->assertSame(0, ChatMessage::query()->count());
    }

    public function test_independent_round_and_received_messages_lead_to_explicit_consensus_and_report(): void
    {
        [$story, $owner] = $this->story();
        $prompts = [];
        $agents = Mockery::mock(AgentProfileClient::class);
        $agents->shouldReceive('sendMessage')->times(6)->andReturnUsing(function ($slug, $prompt, $conversation) use (&$prompts) {
            $prompts[$slug][] = $prompt;
            $round = count($prompts[$slug]);
            $point = $round === 1 ? ['backend-developer' => 3, 'frontend-developer' => 5, 'qa-engineer' => 8][$slug] : 5;

            return json_encode(['estimate_points' => $point, 'rationale' => 'Analisis nyata '.$slug.' putaran '.$round] + ($round === 2 ? ['response' => 'Menanggapi pendapat tim.', 'explicit_agree' => true] : []));
        });
        $this->app->instance(AgentProfileClient::class, $agents);
        $sm = Mockery::mock(HermesClient::class);
        $sm->shouldReceive('sendMessage')->once()->withArgs(function ($prompt) {
            $this->assertStringContainsString('Analisis nyata qa-engineer putaran 2', $prompt);

            return true;
        })->andReturn('Laporan berdasar diskusi.');
        $this->app->instance(HermesClient::class, $sm);

        $run = app(StoryEstimationService::class)->estimate($story, $owner);

        $this->assertSame('COMPLETED', $run->status);
        $this->assertSame(5, $run->consensus_points);
        $this->assertNotNull($run->report_sent_at);
        $this->assertSame(5, $story->fresh()->estimate_points);
        $this->assertCount(18, $run->messages);
        $this->assertSame(6, $run->agentRuns()->count());
        $this->assertSame(1, $run->messages()->where('sender_slug', 'backend-developer')->where('recipient_slug', 'frontend-developer')->where('round', 1)->count());
        $this->assertStringContainsString('Analisis nyata qa-engineer putaran 1', $prompts['backend-developer'][1]);
        $this->assertStringContainsString('Analisis nyata frontend-developer putaran 1', $prompts['backend-developer'][1]);
        $this->assertStringNotContainsString('Analisis nyata frontend-developer putaran 1', $prompts['backend-developer'][0]);
        $this->assertSame('Sedang', StoryEstimationService::category(5));
    }

    public function test_disagreement_runs_three_complete_rounds_without_assigning_point(): void
    {
        [$story, $owner] = $this->story();
        $prompts = [];
        $agents = Mockery::mock(AgentProfileClient::class);
        $agents->shouldReceive('sendMessage')->times(9)->andReturnUsing(function ($slug, $prompt) use (&$prompts) {
            $prompts[$slug][] = $prompt;
            $round = count($prompts[$slug]);

            return json_encode(['estimate_points' => ['backend-developer' => 3, 'frontend-developer' => 5, 'qa-engineer' => 8][$slug],
                'rationale' => 'Alasan '.$slug.' ronde '.$round] + ($round > 1
                    ? ['response' => 'Tidak setuju dengan argumen lain.', 'explicit_agree' => false] : []));
        });
        $this->app->instance(AgentProfileClient::class, $agents);
        $sm = Mockery::mock(HermesClient::class);
        $sm->shouldNotReceive('sendMessage');
        $this->app->instance(HermesClient::class, $sm);

        $run = app(StoryEstimationService::class)->estimate($story, $owner);

        $this->assertSame('NO_CONSENSUS', $run->status);
        $this->assertNull($run->consensus_points);
        $this->assertNull($story->fresh()->estimate_points);
        $this->assertSame(9, $run->agentRuns()->count());
        $this->assertSame(27, $run->messages()->count());
        $this->assertStringContainsString('Alasan qa-engineer ronde 2', $prompts['backend-developer'][2]);
        $this->assertStringContainsString('Tidak setuju dengan argumen lain.', $prompts['qa-engineer'][2]);
    }

    public function test_report_failure_preserves_consensus_and_retry_does_not_rerun_agents(): void
    {
        [$story, $owner] = $this->story();
        $agents = Mockery::mock(AgentProfileClient::class);
        $agents->shouldReceive('sendMessage')->times(6)->andReturnUsing(function ($slug, $prompt) {
            $round = str_contains($prompt, 'PUTARAN 1') ? 1 : 2;

            return json_encode(['estimate_points' => 8, 'rationale' => 'Penilaian scope '.$slug]
                + ($round === 2 ? ['response' => 'Setuju pada estimasi 8.', 'explicit_agree' => true] : []));
        });
        $this->app->instance(AgentProfileClient::class, $agents);
        $sm = Mockery::mock(HermesClient::class);
        $sm->shouldReceive('sendMessage')->twice()->andReturnUsing(function ($prompt) {
            static $calls = 0;
            $this->assertStringContainsString('Penilaian scope backend-developer', $prompt);

            return ++$calls === 1 ? '' : 'Laporan atas poin delapan.';
        });
        $this->app->instance(HermesClient::class, $sm);

        $service = app(StoryEstimationService::class);
        $run = $service->estimate($story, $owner);
        $this->assertSame('REPORT_PENDING', $run->status);
        $this->assertSame(8, $run->consensus_points);
        $this->assertNull($run->report_sent_at);
        $this->assertNull($story->fresh()->estimate_points);
        $this->assertSame(18, $run->messages()->count());
        $this->assertSame(0, ChatMessage::query()->count());

        $reported = $service->report($run, $owner);
        $this->assertSame('COMPLETED', $reported->status);
        $this->assertSame(8, $story->fresh()->estimate_points);
        $this->assertNotNull($reported->report_sent_at);
        $this->assertSame(6, $reported->agentRuns()->count());
        $this->assertSame(18, $reported->messages()->count());
        $this->assertSame(1, ChatMessage::query()->count());
        $this->assertSame('Berat', StoryEstimationService::category(8));
        $this->assertSame('Ringan', StoryEstimationService::category(2));
    }

    public function test_equal_points_without_explicit_agreement_do_not_create_consensus(): void
    {
        [$story, $owner] = $this->story();
        $agents = Mockery::mock(AgentProfileClient::class);
        $agents->shouldReceive('sendMessage')->times(9)->andReturnUsing(function ($slug, $prompt) {
            $round = str_contains($prompt, 'PUTARAN 1') ? 1 : (str_contains($prompt, 'PUTARAN 2') ? 2 : 3);

            return json_encode(['estimate_points' => 5, 'rationale' => 'Mandiri']
                + ($round > 1 ? ['response' => 'Belum ada persetujuan.', 'explicit_agree' => false] : []));
        });
        $this->app->instance(AgentProfileClient::class, $agents);
        $sm = Mockery::mock(HermesClient::class);
        $sm->shouldNotReceive('sendMessage');
        $this->app->instance(HermesClient::class, $sm);

        $run = app(StoryEstimationService::class)->estimate($story, $owner);

        $this->assertSame('NO_CONSENSUS', $run->status);
        $this->assertNull($run->consensus_points);
        $this->assertNull($story->fresh()->estimate_points);
    }

    public function test_stale_story_prevents_report_retry_and_never_sets_points(): void
    {
        [$story, $owner] = $this->story();
        $agents = Mockery::mock(AgentProfileClient::class);
        $agents->shouldReceive('sendMessage')->times(6)->andReturnUsing(function ($slug, $prompt) {
            $round = str_contains($prompt, 'PUTARAN 1') ? 1 : 2;

            return json_encode(['estimate_points' => 3, 'rationale' => 'Ukuran story']
                + ($round === 2 ? ['response' => 'Kesimpulan bersama.', 'explicit_agree' => true] : []));
        });
        $this->app->instance(AgentProfileClient::class, $agents);
        $sm = Mockery::mock(HermesClient::class);
        $sm->shouldReceive('sendMessage')->once()->andReturn('');
        $this->app->instance(HermesClient::class, $sm);
        $service = app(StoryEstimationService::class);
        $run = $service->estimate($story, $owner);
        $this->assertSame('REPORT_PENDING', $run->status);
        $story->forceFill(['title' => 'Story berubah diam-diam'])->save();
        $retried = $service->report($run, $owner);

        $this->assertSame('STALE', $retried->status);
        $this->assertSame(3, $retried->consensus_points);
        $this->assertNull($story->fresh()->estimate_points);
        $this->assertSame(0, ChatMessage::query()->count());
    }

    public function test_invalid_or_secret_bearing_json_cannot_be_used_as_evidence(): void
    {
        [$story, $owner] = $this->story();
        $agents = Mockery::mock(AgentProfileClient::class);
        $agents->shouldReceive('sendMessage')->times(3)->andReturnUsing(fn ($slug) => $slug === 'qa-engineer'
            ? '{"estimate_points":5,"rationale":"API_KEY=should-never-appear"}'
            : '{"estimate_points":5,"rationale":"Alasan sah","explicit_agree":"true"}');
        $this->app->instance(AgentProfileClient::class, $agents);
        $sm = Mockery::mock(HermesClient::class);
        $sm->shouldNotReceive('sendMessage');
        $this->app->instance(HermesClient::class, $sm);

        $run = app(StoryEstimationService::class)->estimate($story, $owner);

        $this->assertSame('FAILED', $run->status);
        $this->assertNull($run->consensus_points);
        $this->assertSame(0, $run->messages()->count());
        $this->assertSame(3, $run->agentRuns()->where('status', 'FAILED')->count());
        $this->assertNull($story->fresh()->estimate_points);
    }

    public function test_story_changed_during_last_agent_response_is_stale_not_consensus(): void
    {
        [$story, $owner] = $this->story();
        $agents = Mockery::mock(AgentProfileClient::class);
        $agents->shouldReceive('sendMessage')->times(9)->andReturnUsing(function ($slug, $prompt) use ($story) {
            $round = str_contains($prompt, 'PUTARAN 1') ? 1 : (str_contains($prompt, 'PUTARAN 2') ? 2 : 3);
            if ($round === 3 && $slug === 'qa-engineer') {
                $story->forceFill(['revision' => 2])->save();
            }

            return json_encode(['estimate_points' => 5, 'rationale' => 'Alasan spesialis']
                + ($round > 1 ? ['response' => 'Masih beda pendapat.', 'explicit_agree' => false] : []));
        });
        $this->app->instance(AgentProfileClient::class, $agents);
        $sm = Mockery::mock(HermesClient::class);
        $sm->shouldNotReceive('sendMessage');
        $this->app->instance(HermesClient::class, $sm);

        $run = app(StoryEstimationService::class)->estimate($story, $owner);

        $this->assertSame('STALE', $run->status);
        $this->assertNull($run->consensus_points);
        $this->assertNull($story->fresh()->estimate_points);
    }

    public function test_repeated_request_for_completed_revision_does_not_rerun_specialists(): void
    {
        [$story, $owner] = $this->story();
        $agents = Mockery::mock(AgentProfileClient::class);
        $agents->shouldReceive('sendMessage')->times(6)->andReturnUsing(function ($slug, $prompt) {
            $round = str_contains($prompt, 'PUTARAN 1') ? 1 : 2;

            return json_encode(['estimate_points' => 2, 'rationale' => 'Ukur story']
                + ($round === 2 ? ['response' => 'Sepakat dua.', 'explicit_agree' => true] : []));
        });
        $this->app->instance(AgentProfileClient::class, $agents);
        $sm = Mockery::mock(HermesClient::class);
        $sm->shouldReceive('sendMessage')->once()->andReturn('Laporan dua poin.');
        $this->app->instance(HermesClient::class, $sm);
        $service = app(StoryEstimationService::class);

        $first = $service->estimate($story, $owner);
        $second = $service->estimate($story, $owner);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('COMPLETED', $second->status);
        $this->assertSame(2, $story->fresh()->estimate_points);
    }

    private function story(): array
    {
        $owner = User::factory()->create();
        $project = Project::query()->create(['name' => 'Estimasi', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'SETUP_REQUIRED']);
        $sprint = Sprint::query()->create(['project_id' => $project->id, 'name' => 'Sprint 1', 'status' => 'PLANNING', 'revision' => 1]);
        $story = Story::query()->create([
            'project_id' => $project->id,
            'sprint_id' => $sprint->id,
            'user_story_key' => 'US-01',
            'title' => 'Fitur estimasi',
            'acceptance_criteria' => 'Kriteria nyata.',
            'scope_status' => 'NEEDS_REFINEMENT',
            'revision' => 1,
        ]);

        return [$story, $owner];
    }
}
