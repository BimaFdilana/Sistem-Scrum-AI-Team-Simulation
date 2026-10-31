<?php

namespace Tests\Feature;

use App\Exceptions\AiUnavailableException;
use App\Models\User;
use App\Services\CliProxyApiClient;
use App\Services\HermesClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class AiResilienceTest extends TestCase
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

    private function chatUrl(): string
    {
        return 'http://127.0.0.1:8317/v1/chat/completions';
    }

    private function fakeModels(array $ids): void
    {
        Http::fake([
            'http://127.0.0.1:8317/v1/models' => Http::response([
                'data' => array_map(fn ($id) => ['id' => $id, 'owned_by' => 'openai'], $ids),
            ]),
        ]);
    }

    private function okResponse(string $text = 'Halo, saya SM.')
    {
        return Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => $text]]]], 200);
    }

    public function test_retries_transient_failures_then_succeeds(): void
    {
        $this->fakeModels(['gpt-a']);
        $calls = 0;
        Http::fake(function (Request $request) use (&$calls) {
            if ($request->url() === 'http://127.0.0.1:8317/v1/models') {
                return Http::response(['data' => [['id' => 'gpt-a', 'owned_by' => 'openai']]]);
            }
            $calls++;
            return $calls < 3 ? Http::response('overloaded', 503) : $this->okResponse();
        });

        $result = app(CliProxyApiClient::class)->chatResilient('gpt-a', 'sys', 'halo');

        $this->assertSame('Halo, saya SM.', $result['text']);
        $this->assertNull($result['error']);
        $this->assertSame(3, $calls);
    }

    public function test_does_not_retry_permanent_auth_failure(): void
    {
        $this->fakeModels(['gpt-a']);
        $calls = 0;
        Http::fake(function (Request $request) use (&$calls) {
            if (str_ends_with($request->url(), '/v1/models')) {
                return Http::response(['data' => [['id' => 'gpt-a', 'owned_by' => 'openai']]]);
            }
            $calls++;
            return Http::response('unauthorized', 401);
        });

        $result = app(CliProxyApiClient::class)->chatResilient('gpt-a', 'sys', 'halo');

        $this->assertNull($result['text']);
        $this->assertSame('AUTH_FAILED', $result['error']);
        $this->assertSame(1, $calls);
    }

    public function test_antigravity_missing_project_id_is_reported_as_incomplete_provider_auth(): void
    {
        $this->fakeModels(['gemini-3.5-flash-lite']);
        $calls = 0;
        Http::fake(function (Request $request) use (&$calls) {
            if (str_ends_with($request->url(), '/v1/models')) {
                return Http::response(['data' => [['id' => 'gemini-3.5-flash-lite', 'owned_by' => 'antigravity']]]);
            }
            $calls++;

            return Http::response(['error' => ['message' => 'antigravity auth missing project_id: no project_id in response', 'type' => 'invalid_request_error']], 400);
        });

        $result = app(CliProxyApiClient::class)->chatResilient('gemini-3.5-flash-lite', 'sys', 'halo');

        $this->assertNull($result['text']);
        $this->assertSame('PROVIDER_AUTH_INCOMPLETE', $result['error']);
        $this->assertSame(1, $calls);
    }

    public function test_404_returns_model_problematic_without_trying_other_models(): void
    {
        // Aturan tanpa-default: 404 bukan lagi sinyal fallback ke model
        // lain — alur berhenti dengan MODEL_PROBLEMATIC + daftar
        // available, dan PO yang memilih penggantinya.
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
            return Http::response('no such model', 404);
        });

        $result = app(CliProxyApiClient::class)->chatResilient('gpt-a', 'sys', 'halo');

        $this->assertNull($result['text']);
        $this->assertSame('MODEL_PROBLEMATIC', $result['error']);
        $this->assertSame('gpt-a', $result['model']);
        $this->assertSame(['gpt-a', 'gpt-b'], $result['available_models']);
        $this->assertSame(['gpt-a'], $requested);
    }

    public function test_404_lists_available_models_for_replacement(): void
    {
        $this->fakeModels(['gpt-a', 'gpt-image-1']);
        $requested = [];
        Http::fake(function (Request $request) use (&$requested) {
            if (str_ends_with($request->url(), '/v1/models')) {
                return Http::response(['data' => [
                    ['id' => 'gpt-a', 'owned_by' => 'openai'],
                    ['id' => 'gpt-image-1', 'owned_by' => 'openai'],
                ]]);
            }
            $requested[] = $request['model'];
            return Http::response('no such model', 404);
        });

        $result = app(CliProxyApiClient::class)->chatResilient('gpt-a', 'sys', 'halo');

        $this->assertNull($result['text']);
        $this->assertSame('MODEL_PROBLEMATIC', $result['error']);
        $this->assertSame(['gpt-a', 'gpt-image-1'], $result['available_models']);
        $this->assertSame(['gpt-a'], $requested);
    }

    public function test_gives_up_after_max_attempts_with_last_error(): void
    {
        $this->fakeModels(['gpt-a']);
        $calls = 0;
        Http::fake(function (Request $request) use (&$calls) {
            if (str_ends_with($request->url(), '/v1/models')) {
                return Http::response(['data' => [['id' => 'gpt-a', 'owned_by' => 'openai']]]);
            }
            $calls++;
            return Http::response('overloaded', 503);
        });

        $result = app(CliProxyApiClient::class)->chatResilient('gpt-a', 'sys', 'halo');

        $this->assertNull($result['text']);
        $this->assertSame('UPSTREAM_OVERLOADED', $result['error']);
        $this->assertSame(3, $calls); // 3 percobaan, lalu menyerah
    }

    public function test_timeout_is_classified_and_retried(): void
    {
        $this->fakeModels(['gpt-a']);
        $calls = 0;
        Http::fake(function (Request $request) use (&$calls) {
            if (str_ends_with($request->url(), '/v1/models')) {
                return Http::response(['data' => [['id' => 'gpt-a', 'owned_by' => 'openai']]]);
            }
            $calls++;
            if ($calls === 1) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }
            return $this->okResponse();
        });

        $result = app(CliProxyApiClient::class)->chatResilient('gpt-a', 'sys', 'halo');

        $this->assertSame('Halo, saya SM.', $result['text']);
        $this->assertSame(2, $calls);
    }

    public function test_hermes_throws_specific_exception(): void
    {
        // HermesClient kini me-resolve model SM lewat StrictModelResolver:
        // mock snapshot terverifikasi + katalog gateway, bukan resolveModel.
        $this->mock(\App\Services\AgentConfiguration::class, function (MockInterface $mock) {
            $mock->shouldReceive('current')->with('scrum-master')->andReturn(['status' => 'verified', 'model' => 'gpt-a']);
        });
        $cliproxy = Mockery::mock(CliProxyApiClient::class);
        $cliproxy->shouldReceive('models')->andReturn([['id' => 'gpt-a', 'owned_by' => 'openai']]);
        $cliproxy->shouldReceive('chatResilient')->once()->andReturn([
            'text' => null, 'error' => 'UPSTREAM_OVERLOADED', 'model' => null,
        ]);
        $this->app->instance(CliProxyApiClient::class, $cliproxy);

        try {
            app(HermesClient::class)->sendMessage('halo', 'conv-1');
            $this->fail('Seharusnya melempar AiUnavailableException.');
        } catch (AiUnavailableException $e) {
            $this->assertSame('UPSTREAM_OVERLOADED', $e->errorCode);
        }
    }

    public function test_chat_returns_specific_indonesian_message_on_overload(): void
    {
        $this->fakeModels(['gpt-a']);
        // Chat SM kini ketat: perlu snapshot SM terverifikasi agar
        // sendMessage mencapai gateway (bukan gagal "belum memilih").
        $this->mock(\App\Services\AgentConfiguration::class, function (MockInterface $mock) {
            $mock->shouldReceive('current')->with('scrum-master')->andReturn(['status' => 'verified', 'model' => 'gpt-a']);
        });
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/v1/models')) {
                return Http::response(['data' => [['id' => 'gpt-a', 'owned_by' => 'openai']]]);
            }
            return Http::response('overloaded', 503);
        });

        $response = $this->actingAs(User::factory()->create())
            ->postJson('/chat/messages', ['message' => 'halo, apa kabar?']);

        $response->assertStatus(503);
        $response->assertJsonPath('message', 'Server AI sedang penuh. Tunggu 1–2 menit lalu coba lagi.');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
