<?php

namespace Tests\Feature;

use App\Exceptions\ModelNotChosenException;
use App\Exceptions\ModelProblemException;
use App\Services\AgentConfiguration;
use App\Services\AgentProfileClient;
use App\Services\AgentToolRunner;
use App\Services\AiStatusService;
use App\Services\CliProxyApiClient;
use App\Services\HermesTaskRunner;
use App\Services\StrictModelResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Aturan tanpa-default (Bima 2026-10-31): tidak ada model default di
 * mana pun; hanya model yang dipilih PO di halaman Konfigurasi Agent
 * yang boleh dipakai; model bermasalah memicu prompt penggantian,
 * bukan substitusi diam-diam; kegagalan transien tetap retry pada
 * model yang sama.
 */
class StrictModelNoDefaultTest extends TestCase
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

    private function mockCurrent(?array $snapshot): void
    {
        $this->mock(AgentConfiguration::class, function (MockInterface $mock) use ($snapshot) {
            $mock->shouldReceive('current')->andReturn($snapshot);
        });
    }

    private function fakeModels(array $ids): void
    {
        Cache::forget('cliproxy:models');
        Http::fake([
            'http://127.0.0.1:8317/v1/models' => Http::response(['data' => array_map(
                fn ($id) => ['id' => $id, 'owned_by' => 'openai'], $ids
            )]),
        ]);
    }

    public function test_resolver_rejects_unselected_role(): void
    {
        $this->mockCurrent(null);
        $this->fakeModels(['gpt-a']);

        $resolver = app(StrictModelResolver::class);
        $check = $resolver->check('qa-engineer');

        $this->assertNull($check['model']);
        $this->assertSame('not_chosen', $check['state']);
        $this->assertNull($resolver->resolve('qa-engineer'));
        try {
            $resolver->require('qa-engineer');
            $this->fail('Seharusnya melempar ModelNotChosenException.');
        } catch (ModelNotChosenException $e) {
            $this->assertStringContainsString('belum dipilih di halaman Konfigurasi Agent', $e->getMessage());
        }
    }

    public function test_resolver_rejects_unverified_and_stale_models(): void
    {
        $this->mockCurrent(['status' => 'pending', 'model' => 'gpt-a']);
        $this->fakeModels(['gpt-a']);
        $check = app(StrictModelResolver::class)->check('qa-engineer');
        $this->assertSame('not_verified', $check['state']);
        $this->assertStringContainsString('belum terverifikasi di halaman Konfigurasi Agent', $check['message']);

        $this->mockCurrent(['status' => 'verified', 'model' => 'stale-model']);
        $this->fakeModels(['gpt-a']);
        $check = app(StrictModelResolver::class)->check('qa-engineer');
        $this->assertSame('not_available', $check['state']);
        $this->assertStringContainsString('tidak ditemukan di daftar model gateway', $check['message']);
    }

    public function test_resolver_accepts_verified_available_model(): void
    {
        $this->mockCurrent(['status' => 'verified', 'model' => 'gpt-a']);
        $this->fakeModels(['gpt-a', 'gpt-b']);

        $this->assertSame('gpt-a', app(StrictModelResolver::class)->resolve('qa-engineer'));
    }

    public function test_hermes_ignores_hermes_model_env_when_role_unselected(): void
    {
        // Bukti HERMES_MODEL diabaikan: nilainya di-set, snapshot null,
        // biner diarahkan ke path yang tidak ada — bila runner berani
        // memakai default itu, errornya akan berbeda.
        config(['ai.hermes.model' => 'glm-5.3', 'ai.hermes.binary' => '/nonexistent/hermes-binary']);
        $this->mockCurrent(null);
        $this->fakeModels(['glm-5.3']);

        $dir = storage_path('framework/testing/strict-hermes-'.uniqid());
        mkdir($dir, 0700, true);
        try {
            app(HermesTaskRunner::class)->run('backend-developer', $dir, 'Kerjakan task.', true);
            $this->fail('Seharusnya melempar ModelNotChosenException.');
        } catch (ModelNotChosenException $e) {
            $this->assertStringContainsString('belum dipilih di halaman Konfigurasi Agent', $e->getMessage());
        } finally {
            rmdir($dir);
        }
    }

    public function test_profile_send_message_rejects_unselected_role_without_calling_model(): void
    {
        $this->mockCurrent(null);
        $this->fakeModels(['gpt-a']);

        try {
            app(AgentProfileClient::class)->sendMessage('qa-engineer', 'halo', 'conv-1');
            $this->fail('Seharusnya melempar ModelNotChosenException.');
        } catch (ModelNotChosenException $e) {
            $this->assertStringContainsString('belum dipilih di halaman Konfigurasi Agent', $e->getMessage());
        }

        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/v1/chat/completions'));
    }

    public function test_model_problem_403_lists_available_and_tries_nothing_else(): void
    {
        $this->fakeModels(['gpt-a', 'gpt-b']);
        $requested = [];
        Http::fake(function (Request $request) use (&$requested) {
            if (str_ends_with($request->url(), '/v1/models')) {
                return Http::response(['data' => [
                    ['id' => 'gpt-a', 'owned_by' => 'openai'],
                    ['id' => 'gpt-b', 'owned_by' => 'openai'],
                ]]);
            }
            $requested[] = $request['model'];

            return Http::response(['error' => ['message' => 'model_disabled: model is disabled']], 403);
        });

        $result = app(CliProxyApiClient::class)->chatResilient('gpt-a', 'sys', 'halo');

        $this->assertNull($result['text']);
        $this->assertSame('MODEL_PROBLEMATIC', $result['error']);
        $this->assertSame('gpt-a', $result['model']);
        $this->assertSame(['gpt-a', 'gpt-b'], $result['available_models']);
        $this->assertSame(['gpt-a'], $requested);
    }

    public function test_model_problem_400_unknown_provider(): void
    {
        $this->fakeModels(['gpt-a']);
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/v1/models')) {
                return Http::response(['data' => [['id' => 'gpt-a', 'owned_by' => 'openai']]]);
            }

            return Http::response(['error' => ['message' => 'unknown provider for model']], 400);
        });

        $result = app(CliProxyApiClient::class)->chatResilient('gpt-a', 'sys', 'halo');

        $this->assertSame('MODEL_PROBLEMATIC', $result['error']);
        $this->assertSame(['gpt-a'], $result['available_models']);
    }

    public function test_tool_history_model_problem(): void
    {
        $this->fakeModels(['gpt-a']);
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/v1/models')) {
                return Http::response(['data' => [['id' => 'gpt-a', 'owned_by' => 'openai']]]);
            }

            return Http::response(['error' => ['message' => 'model_disabled']], 403);
        });

        $result = app(CliProxyApiClient::class)->chatWithToolsHistory('gpt-a', 'sys', [['role' => 'user', 'content' => 'halo']], AgentToolRunner::tools());

        $this->assertSame('MODEL_PROBLEMATIC', $result['error']);
        $this->assertSame('gpt-a', $result['model']);
        $this->assertSame(['gpt-a'], $result['available_models']);
    }

    public function test_transient_429_does_not_trigger_replacement_prompt(): void
    {
        $this->fakeModels(['gpt-a']);
        $requested = [];
        Http::fake(function (Request $request) use (&$requested) {
            if (str_ends_with($request->url(), '/v1/models')) {
                return Http::response(['data' => [['id' => 'gpt-a', 'owned_by' => 'openai']]]);
            }
            $requested[] = $request['model'];
            if (count($requested) < 3) {
                return Http::response('rate limited', 429);
            }

            return Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => 'pulih']]]], 200);
        });

        $result = app(CliProxyApiClient::class)->chatResilient('gpt-a', 'sys', 'halo');

        $this->assertSame('pulih', $result['text']);
        $this->assertNull($result['error']);
        $this->assertSame(['gpt-a', 'gpt-a', 'gpt-a'], $requested);
    }

    public function test_persistent_429_fails_as_rate_limited_not_model_problem(): void
    {
        $this->fakeModels(['gpt-a']);
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/v1/models')) {
                return Http::response(['data' => [['id' => 'gpt-a', 'owned_by' => 'openai']]]);
            }

            return Http::response('rate limited', 429);
        });

        $result = app(CliProxyApiClient::class)->chatResilient('gpt-a', 'sys', 'halo');

        $this->assertNull($result['text']);
        $this->assertSame('RATE_LIMITED', $result['error']);
        $this->assertArrayNotHasKey('available_models', $result);
    }

    public function test_status_service_shows_unselected_role_honestly(): void
    {
        $this->mockCurrent(null);
        $this->fakeModels(['gpt-a']);

        $agent = app(AiStatusService::class)->agents()['qa-engineer'];

        $this->assertFalse($agent['connected']);
        $this->assertNull($agent['model']);
        $this->assertStringContainsString('belum dipilih', $agent['reason']);
    }

    public function test_no_path_returns_a_model_when_config_is_empty(): void
    {
        // Config kosong total: tidak ada snapshot, tidak ada default
        // yang bisa dikarang — resolver, profile client, dan status
        // semua sepakat "tidak ada model".
        $this->mockCurrent(null);
        $this->fakeModels([]);
        config(['ai.hermes.model' => null]);

        $this->assertNull(app(StrictModelResolver::class)->resolve('backend-developer'));
        $this->assertFalse(app(AgentProfileClient::class)->isAvailable('backend-developer'));
        $this->assertFalse(app(AiStatusService::class)->agents()['backend-developer']['connected']);
    }

    private function toolWorktree(): string
    {
        $dir = storage_path('framework/testing/strict-tool-'.uniqid());
        mkdir($dir, 0700, true);
        file_put_contents($dir.'/hello.txt', 'hello');

        return $dir;
    }

    private function malformedResponse(): array
    {
        return ['choices' => [[
            'message' => [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_'.uniqid(),
                    'function' => ['name' => 'read_file', 'arguments' => '{bukan json'],
                ]],
            ],
        ]]];
    }

    private function fakeToolGateway(array $chatResponses): void
    {
        Cache::forget('cliproxy:models');
        $sequence = Http::sequence();
        foreach ($chatResponses as $response) {
            $sequence->push($response);
        }
        Http::fake([
            'http://127.0.0.1:8317/v1/models' => Http::response(['data' => [
                ['id' => 'gemini-3.1-flash-lite', 'owned_by' => 'google'],
            ]]),
            'http://127.0.0.1:8317/v1/chat/completions' => $sequence,
        ]);
    }

    public function test_repeated_malformed_responses_become_model_problem(): void
    {
        $this->fakeToolGateway([
            $this->malformedResponse(),
            $this->malformedResponse(),
            $this->malformedResponse(),
        ]);

        try {
            app(AgentToolRunner::class)->runWithModel('frontend-developer', 'gemini-3.1-flash-lite', $this->toolWorktree(), 'Baca file.', true);
            $this->fail('Seharusnya melempar ModelProblemException.');
        } catch (ModelProblemException $e) {
            $this->assertStringContainsString('MODEL_PROBLEMATIC', $e->getMessage());
            $this->assertStringContainsString('gemini-3.1-flash-lite', $e->getMessage());
        }
    }

    public function test_malformed_then_valid_tool_call_repairs_in_loop(): void
    {
        $this->fakeToolGateway([
            $this->malformedResponse(),
            $this->malformedResponse(),
            ['choices' => [[
                'message' => [
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_'.uniqid(),
                        'function' => ['name' => 'read_file', 'arguments' => json_encode(['path' => 'hello.txt'])],
                    ]],
                ],
            ]]],
            ['choices' => [[
                'message' => [
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_'.uniqid(),
                        'function' => ['name' => 'submit_task', 'arguments' => json_encode(['summary' => 'Selesai setelah repair.'])],
                    ]],
                ],
            ]]],
        ]);

        $result = app(AgentToolRunner::class)->runWithModel('frontend-developer', 'gemini-3.1-flash-lite', $this->toolWorktree(), 'Baca file.', true);

        $this->assertSame('Selesai setelah repair.', $result['summary']);
    }
}
