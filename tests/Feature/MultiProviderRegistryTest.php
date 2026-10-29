<?php

namespace Tests\Feature;

use App\Services\AiUsageRecorder;
use App\Services\CliProxyApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Multi-API, multi-akun (2026-10-30): provider baru yang didaftarkan
 * gateway (mis. Mimo lewat API key) harus ikut routing aplikasi —
 * pengelompokan model, fallback lintas provider, dan label pemakaian —
 * cukup dengan menambahkannya di registry config ai.model_providers,
 * tanpa mengubah logika routing. Multi-akun sendiri adalah urusan
 * gateway (rotasi + failover antar kandidat auth untuk model yang
 * sama) dan dibuktikan di sini lewat perilaku fallback, bukan kode.
 */
class MultiProviderRegistryTest extends TestCase
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
            'ai.cliproxy.chat_attempts' => 1,
            'ai.cliproxy.chat_fallbacks' => 3,
        ]);
    }

    private array $models = [
        ['id' => 'gemini-2.5-flash', 'owned_by' => 'antigravity'],
        ['id' => 'gpt-oss-120b', 'owned_by' => 'openai'],
        ['id' => 'claude-sonnet-4-6', 'owned_by' => 'anthropic'],
        ['id' => 'mimo-7b-flash', 'owned_by' => 'mimo'],
    ];

    private function fakeGateway(callable $chat): void
    {
        $models = $this->models;
        Http::fake(function (Request $request) use ($models, $chat) {
            if (str_ends_with($request->url(), '/v1/models')) {
                return Http::response(['data' => $models]);
            }

            return $chat($request);
        });
    }

    private function okResponse(string $text)
    {
        return Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => $text]]]], 200);
    }

    public function test_new_provider_models_are_grouped_by_registry(): void
    {
        $this->fakeGateway(fn () => $this->okResponse('ok'));
        $grouped = app(CliProxyApiClient::class)->modelsByProvider();

        $this->assertSame(['gemini-2.5-flash'], $grouped['google']);
        $this->assertSame(['gpt-oss-120b'], $grouped['codex']);
        $this->assertSame(['claude-sonnet-4-6'], $grouped['anthropic']);
        $this->assertSame(['mimo-7b-flash'], $grouped['mimo']);
        $this->assertSame('mimo', app(CliProxyApiClient::class)->providerForModel('mimo-7b-flash'));
        $this->assertNull(app(CliProxyApiClient::class)->providerForModel('model-tidak-ada'));
    }

    public function test_new_provider_is_reached_by_cross_provider_fallback(): void
    {
        $this->fakeGateway(function (Request $request) {
            return match ($request['model']) {
                'mimo-7b-flash' => Http::response('rate limited', 429),
                default => $this->okResponse('dari Google'),
            };
        });

        $result = app(CliProxyApiClient::class)->chatResilient('mimo-7b-flash', 'sys', 'halo');

        $this->assertSame('dari Google', $result['text']);
        $this->assertSame('gemini-2.5-flash', $result['model']);
    }

    public function test_fourth_provider_is_reached_when_others_are_rate_limited(): void
    {
        $this->fakeGateway(function (Request $request) {
            return $request['model'] === 'mimo-7b-flash'
                ? $this->okResponse('dari Mimo')
                : Http::response('rate limited', 429);
        });

        $result = app(CliProxyApiClient::class)->chatResilient('gpt-oss-120b', 'sys', 'halo');

        $this->assertSame('dari Mimo', $result['text']);
        $this->assertSame('mimo-7b-flash', $result['model']);
    }

    public function test_usage_labels_cover_new_provider_and_preserve_old_ones(): void
    {
        $this->assertSame('Google', AiUsageRecorder::providerForModel('gemini-2.5-flash'));
        $this->assertSame('Anthropic', AiUsageRecorder::providerForModel('claude-sonnet-4-6'));
        $this->assertSame('OpenAI', AiUsageRecorder::providerForModel('gpt-oss-120b'));
        $this->assertSame('Mimo', AiUsageRecorder::providerForModel('mimo-7b-flash'));
        $this->assertSame('Lainnya', AiUsageRecorder::providerForModel('sesuatu-asing'));
    }
}
