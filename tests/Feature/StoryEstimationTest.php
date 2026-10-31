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
            if (str_ends_with($request->url(), '/v1/models')) {
                return Http::response(['data' => [
                    ['id' => 'be-model', 'owned_by' => 'openai'],
                    ['id' => 'fe-model', 'owned_by' => 'openai'],
                    ['id' => 'qa-model', 'owned_by' => 'openai'],
                    ['id' => 'sm-model', 'owned_by' => 'openai'],
                ]]);
            }
            if ($request['model'] === 'sm-model') {
                return Http::response(['choices' => [['message' => ['content' => 'Scrum Master: ketiganya setuju 5 poin.']]]]);
            }
            $content = $request['messages'][1]['content'] ?? '';
            $finalConfirmation = str_contains($content, 'KONFIRMASI FINAL PUTARAN 2');
            $secondRound = str_contains($content, 'PUTARAN 2');
            $point = ($secondRound || $finalConfirmation) ? 5 : match ($request['model']) {
                'be-model' => 3,
                'fe-model' => 5,
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
        $this->assertDatabaseCount('story_estimation_agent_runs', 6);
        $this->assertDatabaseCount('story_estimation_messages', 18);
        $this->assertStringContainsString('ketiganya setuju 5 poin', ChatMessage::query()->where('role', 'assistant')->value('content'));
        // 8 chat completions = 6 vote + 1 ringkasan SM + 1 laporan SM
        // (1 request katalog model tidak dihitung).
        $this->assertCount(8, Http::recorded(fn ($request) => str_ends_with($request->url(), '/v1/chat/completions')));
        $this->get('/agents/backend-developer/activity')->assertOk()->assertSee('REFINEMENT FIBONACCI');
    }

    public function test_qa_answer_wrapped_in_markdown_fence_and_prose_is_still_accepted(): void
    {
        $this->configureEstimationProfiles();
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/v1/models')) {
                return Http::response(['data' => [
                    ['id' => 'be-model', 'owned_by' => 'openai'],
                    ['id' => 'fe-model', 'owned_by' => 'openai'],
                    ['id' => 'qa-model', 'owned_by' => 'openai'],
                    ['id' => 'sm-model', 'owned_by' => 'openai'],
                ]]);
            }
            if ($request['model'] === 'sm-model') {
                return Http::response(['choices' => [['message' => ['content' => 'Scrum Master: ketiganya setuju 5 poin.']]]]);
            }
            $content = $request['messages'][1]['content'] ?? '';
            $isFinal = str_contains($content, 'KONFIRMASI FINAL PUTARAN 2');
            if ($isFinal) {
                $response = ['estimate_points' => 5, 'rationale' => 'Konfirmasi akhir setelah membaca ringkasan.', 'response' => 'Saya menyetujui 5 poin.', 'explicit_agree' => true];
            } else {
                $response = ['estimate_points' => 5, 'rationale' => 'Cakupan keseluruhan dan kriteria diperiksa.'];
            }
            $body = json_encode($response);
            // QA membungkus JSON dengan prosa + pagar kode (gaya jawaban yang
            // sebelumnya ditolak INVALID_JSON_SCHEMA); BE/FE mengembalikan murni.
            if ($request['model'] === 'qa-model' && ! $isFinal) {
                $body = "Berikut estimasi saya:\n```json\n".$body."\n```\nSemoga membantu.";
            }

            return Http::response(['choices' => [['message' => ['content' => $body]]]]);
        });
        [$owner, $project, $story] = $this->makeStory();
        $this->actingAs($owner)->post('/stories/'.$story->id.'/estimation-runs')
            ->assertRedirect('/projects/'.$project->id.'/sprint-backlog')->assertSessionHas('status');
        $run = StoryEstimationRun::query()->where('story_id', $story->id)->firstOrFail();
        $this->assertSame('COMPLETED', $run->status);
        $this->assertSame(5, $run->consensus_points);
        $this->assertSame(5, $story->fresh()->estimate_points);
        $this->assertSame('COMPLETED', $run->agentRuns()->where('profile_slug', 'qa-engineer')->where('round', 1)->value('status'));
    }

    public function test_deadlock_applies_maximum_and_queues_sm_report(): void
    {
        $this->configureEstimationProfiles();
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/v1/models')) {
                return Http::response(['data' => [
                    ['id' => 'be-model', 'owned_by' => 'openai'],
                    ['id' => 'fe-model', 'owned_by' => 'openai'],
                    ['id' => 'qa-model', 'owned_by' => 'openai'],
                    ['id' => 'sm-model', 'owned_by' => 'openai'],
                ]]);
            }
            if ($request['model'] === 'sm-model') {
                return Http::response([], 500);
            }
            $point = match ($request['model']) {
                'be-model' => 3,
                'fe-model' => 5,
                default => 8,
            };
            $content = $request['messages'][1]['content'] ?? '';
            $finalConfirmation = str_contains($content, 'KONFIRMASI FINAL PUTARAN 2');
            $first = str_contains($content, 'PUTARAN 1');
            $answer = ['estimate_points' => $point, 'rationale' => 'Belum ada dasar menyamakan ukuran.'];
            if ($finalConfirmation) {
                $answer = ['estimate_points' => $point, 'explicit_agree' => false, 'rationale' => 'Masih ada perbedaan.', 'response' => 'Masih ada perbedaan.'];
            } elseif (! $first) {
                $answer['response'] = 'Saya membaca usulan lain, tetapi tetap berbeda.';
                $answer['explicit_agree'] = false;
            }

            return Http::response(['choices' => [['message' => ['content' => json_encode($answer)]]]]);
        });
        [$owner, $project, $story] = $this->makeStory();
        $this->actingAs($owner)->post('/stories/'.$story->id.'/estimation-runs')
            ->assertRedirect('/projects/'.$project->id.'/sprint-backlog')->assertSessionHas('status');
        // Deadlock (3/5/8 tanpa persetujuan bulat) -> SM terapkan maksimum = 8.
        // Laporan SM gagal (HTTP 500) sehingga run bertahan REPORT_PENDING untuk retry.
        $this->assertDatabaseHas('story_estimation_runs', ['story_id' => $story->id, 'status' => 'REPORT_PENDING', 'consensus_points' => 8, 'consensus_method' => 'deadlock_max']);
        $this->assertDatabaseCount('story_estimation_agent_runs', 6);
        $this->assertDatabaseCount('story_estimation_messages', 18);
        $this->assertNull($story->fresh()->estimate_points);
        $this->assertDatabaseCount('chat_messages', 0);
        // 9 chat completions = 6 vote + 3 percobaan laporan SM (500
        // di-retry pada model yang sama, bukan diganti model lain).
        $this->assertCount(9, Http::recorded(fn ($request) => str_ends_with($request->url(), '/v1/chat/completions')));
    }

    public function test_backlog_shows_read_only_discussion_and_hides_manual_point_selection(): void
    {
        [$owner, $project, $story] = $this->makeStory();
        $this->actingAs($owner)->get('/projects/'.$project->id.'/sprint-backlog')
            ->assertOk()->assertSee('Diskusi Fibonacci via Scrum Master')
            ->assertSee('Lihat Diskusi Tim')->assertDontSee('Poin final pilihan PO')
            ->assertSee('fibonacci-progress-dialog', false)
            ->assertSee('data-fibonacci-form', false)
            ->assertSee('data-fibonacci-progress-bar', false);
        $this->get('/stories/'.$story->id.'/discussion')->assertOk()
            ->assertSee('Diskusi Fibonacci')->assertSee('Eksekusi &amp; Revisi QA', false);
    }

    private function configureEstimationProfiles(): void
    {
        // Estimasi kini lewat gateway CLIProxyAPI dengan model per role
        // dari menu (aturan tanpa-default) — bukan lagi endpoint
        // per-role legacy. Model per role dibedakan agar fake gateway
        // bisa menjawab per peran.
        config([
            'ai.cliproxy.agents_enabled' => true,
            'ai.cliproxy.api_key' => 'test-key',
            'ai.cliproxy.base_url' => 'http://127.0.0.1:8317',
        ]);
        $this->mock(\App\Services\AgentConfiguration::class, function (\Mockery\MockInterface $mock) {
            $mock->shouldReceive('current')->with('backend-developer')->andReturn(['status' => 'verified', 'model' => 'be-model']);
            $mock->shouldReceive('current')->with('frontend-developer')->andReturn(['status' => 'verified', 'model' => 'fe-model']);
            $mock->shouldReceive('current')->with('qa-engineer')->andReturn(['status' => 'verified', 'model' => 'qa-model']);
            $mock->shouldReceive('current')->with('scrum-master')->andReturn(['status' => 'verified', 'model' => 'sm-model']);
        });
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
