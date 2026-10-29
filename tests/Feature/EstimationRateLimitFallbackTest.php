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
 * sedang cooldown, sementara model Google sehat. Dua celah diperbaiki:
 * (1) failureCode tidak mengenali RATE_LIMITED sehingga semua gagal tampil
 * generik; (2) fallback berhenti di provider yang sama sehingga model
 * Google tidak pernah menyelamatkan peran Backend/QA.
 */
class EstimationRateLimitFallbackTest extends TestCase
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
            'ai.cliproxy.chat_fallbacks' => 2,
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

    public function test_cross_provider_fallback_rescues_rate_limited_primary(): void
    {
        $this->fakeModels([
            ['id' => 'claude-opus-4-6', 'owned_by' => 'anthropic'],
            ['id' => 'gemini-2.5-flash', 'owned_by' => 'google'],
        ]);
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/v1/models')) {
                return Http::response(['data' => [
                    ['id' => 'claude-opus-4-6', 'owned_by' => 'anthropic'],
                    ['id' => 'gemini-2.5-flash', 'owned_by' => 'google'],
                ]]);
            }

            return $request['model'] === 'claude-opus-4-6'
                ? Http::response('rate limited', 429)
                : $this->okResponse('dari Google');
        });

        $result = app(CliProxyApiClient::class)->chatResilient('claude-opus-4-6', 'sys', 'halo');

        $this->assertSame('dari Google', $result['text']);
        $this->assertSame('gemini-2.5-flash', $result['model']);
        $this->assertNull($result['error']);
    }

    public function test_same_provider_alternate_is_tried_before_cross_provider(): void
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

            return match ($request['model']) {
                'claude-a' => Http::response('no such model', 404),
                'claude-b' => Http::response('rate limited', 429),
                default => $this->okResponse('dari Google'),
            };
        });

        $result = app(CliProxyApiClient::class)->chatResilient('claude-a', 'sys', 'halo');

        $this->assertSame('dari Google', $result['text']);
        $this->assertSame('gemini-x', $result['model']);
        $this->assertSame(['claude-a', 'claude-b', 'claude-b', 'claude-b', 'gemini-x'], $requested);
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
    }

    public function test_failed_send_message_carries_attempted_model(): void
    {
        $cliproxy = \Mockery::mock(CliProxyApiClient::class);
        $cliproxy->shouldReceive('resolveModel')->with('qa-engineer')->andReturn('claude-opus-4-6');
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
