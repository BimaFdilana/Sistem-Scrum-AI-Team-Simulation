<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AiStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Provider terhubung → agent harus terhubung: kedua halaman membaca dari
 * AiStatusService yang sama sehingga statusnya tidak mungkin bertentangan.
 */
class AiStatusSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'ai.cliproxy.base_url' => 'http://127.0.0.1:8317',
            'ai.cliproxy.management_key' => 'test-mgmt-key',
            'ai.cliproxy.agents_enabled' => true,
        ]);
    }

    private function fakeGateway(array $files, array $models, int $modelsStatus = 200): void
    {
        Http::fake([
            'http://127.0.0.1:8317/v0/management/auth-files' => Http::response(['files' => $files]),
            'http://127.0.0.1:8317/v1/models' => Http::response(['data' => $models], $modelsStatus),
        ]);
    }

    private function codexFile(bool $disabled): array
    {
        return [
            'id' => 'codex-abc-user@example.com-plus.json',
            'name' => 'codex-abc-user@example.com-plus.json',
            'provider' => 'codex',
            'email' => 'user@example.com',
            'disabled' => $disabled,
        ];
    }

    public function test_provider_connected_means_agents_connected(): void
    {
        $this->fakeGateway(
            [$this->codexFile(false)],
            [['id' => 'gpt-5.6-sol', 'owned_by' => 'openai']]
        );

        $service = app(AiStatusService::class);
        $providers = $service->providers();
        $agents = $service->agents();

        $this->assertTrue($providers['codex']['connected']);
        $this->assertFalse($providers['antigravity']['connected']);

        // Semua peran fallback ke Codex yang terhubung.
        foreach (['scrum-master', 'backend-developer', 'frontend-developer', 'qa-engineer'] as $slug) {
            $this->assertTrue($agents[$slug]['connected'], "Agent {$slug} harus terhubung.");
            $this->assertSame('gpt-5.6-sol', $agents[$slug]['model']);
            $this->assertSame($providers['codex']['label'], $agents[$slug]['provider']);
        }
    }

    public function test_provider_disconnected_means_agents_disconnected_with_reason(): void
    {
        $this->fakeGateway([$this->codexFile(true)], [], 401);

        $service = app(AiStatusService::class);

        $this->assertFalse($service->providers()['codex']['connected']);

        foreach ($service->agents() as $slug => $agent) {
            $this->assertFalse($agent['connected'], "Agent {$slug} harus belum terhubung.");
            $this->assertSame('Belum ada provider AI yang terhubung.', $agent['reason']);
        }
    }

    public function test_connected_provider_without_models_gives_honest_reason(): void
    {
        $this->fakeGateway([$this->codexFile(false)], []);

        $agents = app(AiStatusService::class)->agents();

        foreach ($agents as $slug => $agent) {
            $this->assertFalse($agent['connected']);
            $this->assertStringContainsString('Provider terhubung', $agent['reason']);
        }
    }

    public function test_agents_page_shows_provider_chain(): void
    {
        $this->fakeGateway(
            [$this->codexFile(false)],
            [['id' => 'gpt-5.6-sol', 'owned_by' => 'openai']]
        );

        $response = $this->actingAs(User::factory()->create())->get('/agents');

        $response->assertOk();
        $response->assertSee('via ChatGPT (Codex) · gpt-5.6-sol', false);
    }

    public function test_providers_page_shows_connected(): void
    {
        $this->fakeGateway(
            [$this->codexFile(false)],
            [['id' => 'gpt-5.6-sol', 'owned_by' => 'openai']]
        );

        $response = $this->actingAs(User::factory()->create())->get('/ai-providers');

        $response->assertOk();
        $response->assertSee('Terhubung');
        $response->assertSee('user@example.com');
    }
}
