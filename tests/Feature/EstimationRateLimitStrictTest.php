<?php

namespace Tests\Feature;

use App\Exceptions\AiUnavailableException;
use App\Models\Actor;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Story;
use App\Models\StoryEstimationRun;
use App\Models\User;
use App\Services\CliProxyApiClient;
use App\Services\StoryEstimationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Regresi insiden estimasi laundry Run 9–12 (2026-10-30): model Backend
 * (Codex) dan QA (Claude) menjawab 429 karena satu-satunya kandidat auth
 * sedang cooldown. Satu celah diperbaiki saat itu dan dipertahankan:
 * failureCode mengenali RATE_LIMITED sehingga gagal tidak tampil
 * generik. Celah kedua — rescue lintas provider — SENGAJA DIHAPUS oleh
 * aturan tanpa-default (Bima 2026-10-31): 429 kini di-retry pada model
 * yang sama lalu antre ulang level job; model bermasalah (404/403)
 * berhenti dengan MODEL_PROBLEMATIC + prompt penggantian. Tidak ada
 * lagi substitusi diam-diam ke model lain.
 */
class EstimationRateLimitStrictTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'ai.cliproxy.agents_enabled' => true,
            'ai.cliproxy.api_key' => 'test-key',
            'ai.cliproxy.base_url' => 'http://127.0.0.1:8317',
            'ai.cliproxy.chat_attempts' => 3,
        ]);
    }

    private function fakeModels(array $models): void
    {
        Http::fake([
            'http://127.0.0.1:8317/v1/models' => Http::response(['data' => $models]),
        ]);
    }

    private function okResponse(string $text)
    {
        return Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => $text]]]], 200);
    }

    public function test_rate_limited_primary_is_retried_not_replaced(): void
    {
        // Aturan tanpa-default: 429 di-retry pada model yang sama
        // (3 attempts), lalu gagal sebagai RATE_LIMITED — model Google
        // yang sehat TIDAK dipakai diam-diam sebagai pengganti.
        $this->fakeModels([
            ['id' => 'claude-opus-4-6', 'owned_by' => 'anthropic'],
            ['id' => 'gemini-2.5-flash', 'owned_by' => 'google'],
        ]);
        $requested = [];
        Http::fake(function (Request $request) use (&$requested) {
            if (str_ends_with($request->url(), '/v1/models')) {
                return Http::response(['data' => [
                    ['id' => 'claude-opus-4-6', 'owned_by' => 'anthropic'],
                    ['id' => 'gemini-2.5-flash', 'owned_by' => 'google'],
                ]]);
            }
            $requested[] = $request['model'];

            return Http::response('rate limited', 429);
        });

        $result = app(CliProxyApiClient::class)->chatResilient('claude-opus-4-6', 'sys', 'halo');

        $this->assertNull($result['text']);
        $this->assertSame('RATE_LIMITED', $result['error']);
        $this->assertSame(['claude-opus-4-6', 'claude-opus-4-6', 'claude-opus-4-6'], $requested);
    }

    public function test_404_is_model_problem_not_fallback_chain(): void
    {
        $models = [
            ['id' => 'claude-a', 'owned_by' => 'anthropic'],
            ['id' => 'claude-b', 'owned_by' => 'anthropic'],
            ['id' => 'gemini-x', 'owned_by' => 'google'],
        ];
        $this->fakeModels($models);
        $requested = [];
        Http::fake(function (Request $request) use ($models, &$requested) {
            if (str_ends_with($request->url(), '/v1/models')) {
                return Http::response(['data' => $models]);
            }
            $requested[] = $request['model'];

            return Http::response('no such model', 404);
        });

        $result = app(CliProxyApiClient::class)->chatResilient('claude-a', 'sys', 'halo');

        $this->assertNull($result['text']);
        $this->assertSame('MODEL_PROBLEMATIC', $result['error']);
        $this->assertSame(['claude-a', 'claude-b', 'gemini-x'], $result['available_models']);
        $this->assertSame(['claude-a'], $requested);
    }

    public function test_failure_code_recognizes_rate_limited(): void
    {
        $owner = User::factory()->create();
        $project = Project::query()->create(['name' => 'Rate Limit Workspace', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'SETUP_REQUIRED']);
        $sprint = Sprint::query()->create(['project_id' => $project->id, 'backlog_id' => $project->backlog->id, 'name' => 'Sprint 1', 'status' => 'PLANNING', 'revision' => 1]);
        $actor = Actor::query()->where('name', 'Administrator')->firstOrFail();
        $story = Story::query()->create([
            'project_id' => $project->id, 'sprint_id' => $sprint->id, 'actor_id' => $actor->id, 'user_story_key' => 'US-01',
            'title' => 'Tombol kembali ke atas', 'sprint_purpose' => 'Pengguna mudah kembali ke atas.', 'acceptance_criteria' => 'Tombol muncul setelah scroll.',
            'scope_status' => 'NEEDS_REFINEMENT', 'revision' => 1,
        ]);
        [$run] = app(StoryEstimationService::class)->claimRun($story, $owner);
        $run->forceFill(['status' => 'RUNNING'])->save();

        $method = new ReflectionMethod(StoryEstimationService::class, 'failureCode');
        $service = app(StoryEstimationService::class);

        $this->assertSame('PROVIDER_RATE_LIMITED', $method->invoke($service, new AiUnavailableException('RATE_LIMITED'), $run));
        $this->assertSame('PROVIDER_RATE_LIMITED', $method->invoke($service, new \RuntimeException('AI unavailable: RATE_LIMITED'), $run));
        $this->assertSame('PROVIDER_OVERLOADED', $method->invoke($service, new AiUnavailableException('UPSTREAM_OVERLOADED'), $run));
        $this->assertSame('PROVIDER_REQUEST_FAILED', $method->invoke($service, new AiUnavailableException('AUTH_FAILED'), $run));
        $this->assertSame('MODEL_PROBLEMATIC', $method->invoke($service, new AiUnavailableException('MODEL_PROBLEMATIC'), $run));
        $this->assertSame('MODEL_NOT_CHOSEN', $method->invoke($service, new \App\Exceptions\ModelNotChosenException('belum dipilih'), $run));
    }

    public function test_failed_send_message_carries_attempted_model(): void
    {
        // sendMessage kini me-resolve model QA lewat StrictModelResolver:
        // mock snapshot terverifikasi + katalog gateway, bukan resolveModel.
        $this->mock(\App\Services\AgentConfiguration::class, function (\Mockery\MockInterface $mock) {
            $mock->shouldReceive('current')->with('qa-engineer')->andReturn(['status' => 'verified', 'model' => 'claude-opus-4-6']);
        });
        $cliproxy = \Mockery::mock(CliProxyApiClient::class);
        $cliproxy->shouldReceive('models')->andReturn([['id' => 'claude-opus-4-6', 'owned_by' => 'anthropic']]);
        $cliproxy->shouldReceive('chatResilient')->once()->andReturn([
            'text' => null, 'error' => 'RATE_LIMITED', 'model' => null, 'retry_after' => null,
        ]);
        $this->app->instance(CliProxyApiClient::class, $cliproxy);

        try {
            app(\App\Services\AgentProfileClient::class)->sendMessage('qa-engineer', 'halo', 'conv-1');
            $this->fail('Seharusnya melempar AiUnavailableException.');
        } catch (AiUnavailableException $e) {
            $this->assertSame('RATE_LIMITED', $e->errorCode);
            $this->assertSame('claude-opus-4-6', $e->attemptedModel);
        }
    }

    public function test_report_failure_is_logged_not_silent(): void
    {
        $owner = User::factory()->create();
        $project = Project::query()->create(['name' => 'Report Logging Workspace', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'SETUP_REQUIRED']);
        $sprint = Sprint::query()->create(['project_id' => $project->id, 'backlog_id' => $project->backlog->id, 'name' => 'Sprint 1', 'status' => 'PLANNING', 'revision' => 1]);
        $actor = Actor::query()->where('name', 'Administrator')->firstOrFail();
        $story = Story::query()->create([
            'project_id' => $project->id, 'sprint_id' => $sprint->id, 'actor_id' => $actor->id, 'user_story_key' => 'US-01',
            'title' => 'Tombol kembali ke atas', 'sprint_purpose' => 'Pengguna mudah kembali ke atas.', 'acceptance_criteria' => 'Tombol muncul setelah scroll.',
            'scope_status' => 'NEEDS_REFINEMENT', 'revision' => 1,
        ]);

        $sm = \Mockery::mock(\App\Services\HermesClient::class);
        $sm->shouldReceive('sendMessage')->once()->andThrow(new AiUnavailableException('RATE_LIMITED'));
        $this->app->instance(\App\Services\HermesClient::class, $sm);

        [$run] = app(StoryEstimationService::class)->claimRun($story, $owner);
        $run->forceFill(['status' => 'REPORT_PENDING', 'consensus_points' => 3, 'consensus_method' => 'deadlock_max'])->save();
        $specialists = ['backend-developer', 'frontend-developer', 'qa-engineer'];
        // Putaran 1: 3 pesan ke SM + 6 pesan peer-delivery (tiap spesialis
        // menerima 2 pesan dari dua spesialis lain) — syarat receivedPriorRound.
        foreach ($specialists as $i => $slug) {
            $run->messages()->create([
                'story_revision' => 1, 'round' => 1, 'sender_slug' => $slug, 'recipient_slug' => 'scrum-master',
                'content' => json_encode(['estimate_points' => [1, 2, 3][$i], 'rationale' => 'alasan']), 'estimate_points' => [1, 2, 3][$i],
                'explicit_agree' => false,
            ]);
        }
        foreach ($specialists as $recipient) {
            foreach ($specialists as $i => $sender) {
                if ($sender === $recipient) {
                    continue;
                }
                $run->messages()->create([
                    'story_revision' => 1, 'round' => 1, 'sender_slug' => $sender, 'recipient_slug' => $recipient,
                    'content' => json_encode(['estimate_points' => [1, 2, 3][$i], 'rationale' => 'alasan']), 'estimate_points' => [1, 2, 3][$i],
                    'explicit_agree' => false,
                ]);
            }
        }
        // Putaran 2: 3 pesan final ke SM (deadlock: maks = 3).
        foreach ($specialists as $i => $slug) {
            $run->messages()->create([
                'story_revision' => 1, 'round' => 2, 'sender_slug' => $slug, 'recipient_slug' => 'scrum-master',
                'content' => json_encode(['estimate_points' => [2, 2, 3][$i], 'rationale' => 'alasan']), 'estimate_points' => [2, 2, 3][$i],
                'explicit_agree' => $i === 0,
            ]);
        }

        \Illuminate\Support\Facades\Log::spy();
        $result = app(StoryEstimationService::class)->report($run->fresh(), $owner);

        // Perilaku dipertahankan: tetap REPORT_PENDING agar retry bisa pulih.
        $this->assertSame('REPORT_PENDING', $result->status);
        // Tapi kegagalan tidak boleh diam lagi (regresi Run 14).
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->withArgs(function ($message, $context) use ($run) {
            return $message === 'Story estimation report failed'
                && ($context['run_id'] ?? null) === $run->id
                && ($context['diagnostic'] ?? null) === 'requesting_summary'
                && ($context['exception'] ?? null) === 'AiUnavailableException';
        });
    }

    public function test_report_job_failure_is_logged_not_silent(): void
    {
        $owner = User::factory()->create();
        $project = Project::query()->create(['name' => 'Report Job Workspace', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'SETUP_REQUIRED']);
        $sprint = Sprint::query()->create(['project_id' => $project->id, 'backlog_id' => $project->backlog->id, 'name' => 'Sprint 1', 'status' => 'PLANNING', 'revision' => 1]);
        $actor = Actor::query()->where('name', 'Administrator')->firstOrFail();
        $story = Story::query()->create([
            'project_id' => $project->id, 'sprint_id' => $sprint->id, 'actor_id' => $actor->id, 'user_story_key' => 'US-01',
            'title' => 'Tombol kembali ke atas', 'sprint_purpose' => 'Pengguna mudah kembali ke atas.', 'acceptance_criteria' => 'Tombol muncul setelah scroll.',
            'scope_status' => 'NEEDS_REFINEMENT', 'revision' => 1,
        ]);
        [$run] = app(StoryEstimationService::class)->claimRun($story, $owner);
        $run->forceFill(['status' => 'REPORT_PENDING'])->save();

        $service = \Mockery::mock(StoryEstimationService::class);
        $service->shouldReceive('report')->once()->andThrow(new \RuntimeException('boom'));
        \Illuminate\Support\Facades\Log::spy();

        (new \App\Jobs\SendStoryEstimationReportJob($run->id))->handle($service);

        // Perilaku dipertahankan: job menelan error agar run tetap REPORT_PENDING.
        $this->assertSame('REPORT_PENDING', $run->fresh()->status);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->withArgs(function ($message, $context) use ($run) {
            return $message === 'Story estimation report job failed' && ($context['run_id'] ?? null) === $run->id;
        });
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    public function test_provider_for_model_resolves_from_gateway_catalog(): void
    {
        $this->fakeModels([
            ['id' => 'claude-opus-4-6', 'owned_by' => 'anthropic'],
            ['id' => 'gpt-oss-120b-medium', 'owned_by' => 'openai'],
            ['id' => 'gemini-2.5-flash', 'owned_by' => 'google'],
        ]);
        $client = app(CliProxyApiClient::class);

        $this->assertSame('anthropic', $client->providerForModel('claude-opus-4-6'));
        $this->assertSame('codex', $client->providerForModel('gpt-oss-120b-medium'));
        $this->assertSame('google', $client->providerForModel('gemini-2.5-flash'));
        $this->assertNull($client->providerForModel('model-tidak-ada'));
    }
}
