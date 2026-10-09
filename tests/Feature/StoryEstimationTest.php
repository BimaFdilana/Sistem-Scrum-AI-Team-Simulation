<?php

namespace Tests\Feature;

use App\Models\Actor;
use App\Models\ChatMessage;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Story;
use App\Models\StoryEstimationRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StoryEstimationTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_consensus_and_scrum_master_report_set_final_fibonacci_point_without_p_o_selection(): void
    {
        $this->configureEstimationProfiles();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'scrum-master.test')) {
                return Http::response(['choices' => [['message' => ['content' => 'Scrum Master: ketiganya setuju 5 poin.']]]]);
            }
            $content = $request['messages'][1]['content'] ?? '';
            $finalConfirmation = str_contains($content, 'KONFIRMASI FINAL PUTARAN 3');
            $secondRound = str_contains($content, 'PUTARAN 2');
            $point = ($secondRound || $finalConfirmation) ? 5 : match (true) {
                str_contains($request->url(), 'backend.test') => 3,
                str_contains($request->url(), 'frontend.test') => 5,
                default => 8,
            };
            if ($finalConfirmation) {
                $response = ['estimate_points' => $point, 'explicit_agree' => true, 'rationale' => 'Konfirmasi akhir setelah membaca ringkasan.'];
            } else {
                $response = ['estimate_points' => $point, 'rationale' => 'Cakupan keseluruhan dan kriteria diperiksa.'];
            }
            if ($secondRound) {
                $response['response'] = 'Saya membaca pendapat peserta lain dan menyetujui 5 poin.';
                $response['explicit_agree'] = true;
            }

            return Http::response(['choices' => [['message' => ['content' => json_encode($response)]]]]);
        });
        [$owner, $project, $story] = $this->makeStory();
        $this->actingAs($owner)->post('/stories/'.$story->id.'/estimation-runs')
            ->assertRedirect('/projects/'.$project->id.'/sprint-backlog')->assertSessionHas('status');
        $run = StoryEstimationRun::query()->where('story_id', $story->id)->firstOrFail();
        $this->assertSame('COMPLETED', $run->status);
        $this->assertSame(5, $run->consensus_points);
        $this->assertNotNull($run->report_sent_at);
        $this->assertSame(5, $story->fresh()->estimate_points);
        $this->assertDatabaseCount('story_estimation_agent_runs', 9);
        $this->assertDatabaseCount('story_estimation_messages', 27);
        $this->assertStringContainsString('ketiganya setuju 5 poin', ChatMessage::query()->where('role', 'assistant')->value('content'));
        Http::assertSentCount(10);
        $this->get('/agents/backend-developer/activity')->assertOk()->assertSee('REFINEMENT FIBONACCI');
    }

    public function test_no_consensus_after_three_rounds_never_sets_point_or_fabricates_report(): void
    {
        $this->configureEstimationProfiles();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'scrum-master.test')) {
                return Http::response([], 500);
            }
            $point = match (true) {
                str_contains($request->url(), 'backend.test') => 3,
                str_contains($request->url(), 'frontend.test') => 5,
                default => 8,
            };
            $content = $request['messages'][1]['content'] ?? '';
            $finalConfirmation = str_contains($content, 'KONFIRMASI FINAL PUTARAN 3');
            $first = str_contains($content, 'PUTARAN 1');
            $answer = ['estimate_points' => $point, 'rationale' => 'Belum ada dasar menyamakan ukuran.'];
            if ($finalConfirmation) {
                $answer = ['estimate_points' => $point, 'explicit_agree' => false, 'rationale' => 'Masih ada perbedaan.'];
            } elseif (! $first) {
                $answer['response'] = 'Saya membaca usulan lain, tetapi tetap berbeda.';
                $answer['explicit_agree'] = false;
            }

            return Http::response(['choices' => [['message' => ['content' => json_encode($answer)]]]]);
        });
        [$owner, $project, $story] = $this->makeStory();
        $this->actingAs($owner)->post('/stories/'.$story->id.'/estimation-runs')
            ->assertRedirect('/projects/'.$project->id.'/sprint-backlog')->assertSessionHasErrors('estimation');
        $this->assertDatabaseHas('story_estimation_runs', ['story_id' => $story->id, 'status' => 'NO_CONSENSUS', 'consensus_points' => null]);
        $this->assertDatabaseCount('story_estimation_agent_runs', 9);
        $this->assertDatabaseCount('story_estimation_messages', 27);
        $this->assertNull($story->fresh()->estimate_points);
        $this->assertDatabaseCount('chat_messages', 0);
        Http::assertSentCount(9);
    }

    public function test_backlog_shows_read_only_discussion_and_hides_manual_point_selection(): void
    {
        [$owner, $project, $story] = $this->makeStory();
        $this->actingAs($owner)->get('/projects/'.$project->id.'/sprint-backlog')
            ->assertOk()->assertSee('Diskusi Fibonacci via Scrum Master')
            ->assertSee('Lihat Diskusi Tim')->assertDontSee('Poin final pilihan PO');
        $this->get('/stories/'.$story->id.'/discussion')->assertOk()
            ->assertSee('Diskusi Fibonacci')->assertSee('Eksekusi &amp; Revisi QA', false);
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

    private function makeStory(): array
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
        ]);

        return [$owner, $project, $story];
    }
}
