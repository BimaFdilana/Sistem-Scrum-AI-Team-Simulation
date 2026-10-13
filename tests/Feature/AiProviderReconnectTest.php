<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CliProxyApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiProviderReconnectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ai.cliproxy.base_url' => 'http://127.0.0.1:8317',
            'ai.cliproxy.management_key' => 'test-mgmt-key',
        ]);
    }

    private function mgmt(string $path): string
    {
        return 'http://127.0.0.1:8317/v0/management'.$path;
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

    public function test_account_for_provider_matches_by_slug(): void
    {
        Http::fake([
            $this->mgmt('/auth-files') => Http::response(['files' => [$this->codexFile(false)]]),
        ]);

        $account = app(CliProxyApiClient::class)->accountForProvider('codex');

        $this->assertNotNull($account);
        $this->assertSame('user@example.com', $account['email']);
        $this->assertNull(app(CliProxyApiClient::class)->accountForProvider('antigravity'));
    }

    public function test_account_for_provider_prefers_enabled_over_disabled(): void
    {
        Http::fake([
            $this->mgmt('/auth-files') => Http::response(['files' => [
                $this->codexFile(true),   // file lama, dinonaktifkan
                $this->codexFile(false),  // file baru, aktif
            ]]),
        ]);

        $account = app(CliProxyApiClient::class)->accountForProvider('codex');

        $this->assertNotNull($account);
        $this->assertFalse($account['disabled']);
    }

    public function test_wait_for_account_enabled_detects_disabled(): void
    {
        Http::fake([
            $this->mgmt('/auth-files') => Http::response(['files' => [$this->codexFile(true)]]),
        ]);

        $this->assertFalse(app(CliProxyApiClient::class)->waitForAccountEnabled('codex', 0));
    }

    public function test_wait_for_account_enabled_detects_enabled(): void
    {
        Http::fake([
            $this->mgmt('/auth-files') => Http::response(['files' => [$this->codexFile(false)]]),
        ]);

        $this->assertTrue(app(CliProxyApiClient::class)->waitForAccountEnabled('codex', 0));
    }

    public function test_enable_reactivates_disabled_account_without_oauth(): void
    {
        $calls = 0;
        Http::fake(function ($request) use (&$calls) {
            $url = $request->url();
            if (str_ends_with($url, '/auth-files/status')) {
                $calls++;
                $this->assertFalse($request['disabled']);

                return Http::response(['status' => 'ok', 'disabled' => false]);
            }
            if (str_ends_with($url, '/auth-files')) {
                // Panggilan pertama (dari enable()): masih disabled.
                // Polling berikutnya: sudah aktif.
                static $n = 0;
                $n++;

                return Http::response(['files' => [$this->codexFile($n === 1)]]);
            }

            return Http::response(null, 404);
        });

        $response = $this->actingAs(User::factory()->create())
            ->post('/ai-providers/codex/enable');

        $response->assertRedirect(route('ai-providers.index'));
        $response->assertSessionHas('success');
        $this->assertSame(1, $calls);
    }

    public function test_callback_does_not_claim_success_when_account_stays_disabled(): void
    {
        Http::fake([
            $this->mgmt('/oauth-callback') => Http::response(['status' => 'ok']),
            // Kode diterima gateway, tetapi akun tak pernah aktif
            // (mis. pertukaran kode gagal secara asinkron).
            $this->mgmt('/auth-files') => Http::response(['files' => [$this->codexFile(true)]]),
        ]);

        // waitForAccountEnabled default 30 detik terlalu lama untuk test;
        // pakai mock parsial agar polling langsung gagal.
        $client = \Mockery::mock(CliProxyApiClient::class)->makePartial();
        $client->shouldReceive('submitCallback')->once()->andReturn(true);
        $client->shouldReceive('waitForAccountEnabled')->once()->andReturn(false);
        $this->app->instance(CliProxyApiClient::class, $client);

        $response = $this->actingAs(User::factory()->create())
            ->withSession(['ai_provider_state.codex' => 'state-123'])
            ->post('/ai-providers/codex/callback', [
                'callback_url' => 'http://localhost:8317/callback?code=authcode-xyz&state=state-123',
            ]);

        // Kembali ke form dengan pesan jujur — BUKAN klaim "berhasil terhubung".
        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertStringContainsString(
            'belum aktif',
            (string) session('error')
        );
        $this->assertNotEquals(route('ai-providers.index'), $response->headers->get('Location'));

        \Mockery::close();
    }
}
