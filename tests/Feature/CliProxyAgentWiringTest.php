<?php

namespace Tests\Feature;

use App\Services\AgentProfileClient;
use App\Services\CliProxyApiClient;
use App\Services\HermesClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CliProxyAgentWiringTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.cliproxy.agents_enabled' => true]);
        Cache::forget('cliproxy:models');
    }

    protected function fakeGateway(array $models): void
    {
        Cache::forget('cliproxy:models');
        Http::fake([
            'http://127.0.0.1:8317/v1/models' => Http::response(['data' => $models]),
            'http://127.0.0.1:8317/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Jawaban AI']]],
            ]),
        ]);
    }

    public function test_model_for_role_memetakan_provider_sesuai_peran(): void
    {
        $this->fakeGateway([
            ['id' => 'gpt-5.6-luna', 'owned_by' => 'openai'],
            ['id' => 'claude-opus-4-6', 'owned_by' => 'anthropic'],
            ['id' => 'gemini-2.5-pro', 'owned_by' => 'google'],
        ]);

        $client = app(CliProxyApiClient::class);

        $this->assertSame('claude-opus-4-6', $client->modelForRole('qa-engineer'));
        $this->assertSame('gpt-5.6-luna', $client->modelForRole('backend-developer'));
        $this->assertSame('gemini-2.5-pro', $client->modelForRole('frontend-developer'));
        $this->assertSame('claude-opus-4-6', $client->smartestModel());
    }

    public function test_model_for_role_fallback_ke_model_apapun_bila_provider_belum_ada(): void
    {
        $this->fakeGateway([
            ['id' => 'gpt-5.6-luna', 'owned_by' => 'openai'],
        ]);

        $client = app(CliProxyApiClient::class);

        // QA ingin anthropic, tapi hanya codex yang terhubung -> pakai yang ada.
        $this->assertSame('gpt-5.6-luna', $client->modelForRole('qa-engineer'));
    }

    public function test_model_for_role_null_bila_dimatikan(): void
    {
        config(['ai.cliproxy.agents_enabled' => false]);

        $client = app(CliProxyApiClient::class);

        $this->assertNull($client->modelForRole('qa-engineer'));
        $this->assertNull($client->smartestModel());
        $this->assertNull($client->chat('gpt-5.6-luna', 'sys', 'hi'));
    }

    public function test_agen_terhubung_dan_bisa_chat_lewat_cliproxy(): void
    {
        $this->fakeGateway([
            ['id' => 'gpt-5.6-luna', 'owned_by' => 'openai'],
            ['id' => 'claude-opus-4-6', 'owned_by' => 'anthropic'],
            ['id' => 'gemini-2.5-pro', 'owned_by' => 'google'],
        ]);

        $profiles = app(AgentProfileClient::class);

        $this->assertTrue($profiles->isAvailable('qa-engineer'));
        $this->assertSame('Jawaban AI', $profiles->sendMessage('qa-engineer', 'halo', 'conv-1'));

        $this->assertTrue(app(HermesClient::class)->isAvailable());
        $this->assertSame('Jawaban AI', app(HermesClient::class)->sendMessage('halo', 'conv-2'));

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/chat/completions')
            && $request['model'] === 'claude-opus-4-6');
    }
}
