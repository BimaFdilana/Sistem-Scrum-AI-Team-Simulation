<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CliProxyApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
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

    public function test_disconnect_forgets_models_cache(): void
    {
        Http::fake([
            $this->mgmt('/auth-files/status') => Http::response(['status' => 'ok']),
        ]);

        $client = app(CliProxyApiClient::class);

        Cache::put('cliproxy:models', [['id' => 'x', 'owned_by' => 'openai']], 60);
        $this->assertTrue($client->disableAccount('codex-a.json'));
        $this->assertNull(Cache::get('cliproxy:models'));

        Cache::put('cliproxy:models', [['id' => 'x', 'owned_by' => 'openai']], 60);
        $this->assertTrue($client->setAccountDisabled('codex-a.json', false));
        $this->assertNull(Cache::get('cliproxy:models'));
    }

    public function test_disconnect_clears_provider_cache_and_pending_oauth_state(): void
    {
        Http::fake(function (Request $request) {
            return $request->method() === 'DELETE'
                ? Http::response(['status' => 'ok'])
                : Http::response(['files' => [$this->codexFile(false)]]);
        });

        Cache::put('cliproxy:models', [['id' => 'x', 'owned_by' => 'openai']], 60);

        $response = $this->actingAs(User::factory()->create())
            ->withSession(['ai_provider_state.codex' => 'state-lama'])
            ->post('/ai-providers/codex/disconnect');

        $response->assertRedirect(route('ai-providers.index'));
        $response->assertSessionHas('success');
        // Cache model terhapus ...
        $this->assertNull(Cache::get('cliproxy:models'));
        // ... dan sisa OAuth state ikut dibersihkan.
        $this->assertFalse(session()->has('ai_provider_state.codex'));
    }

    public function test_extract_state_reads_state_from_callback_url(): void
    {
        $this->assertSame(
            'state-xyz',
            CliProxyApiClient::extractState('http://localhost:8317/callback?code=abc&state=state-xyz')
        );
        $this->assertNull(
            CliProxyApiClient::extractState('http://localhost:8317/callback?code=abc')
        );
        $this->assertSame(
            'kode-123',
            CliProxyApiClient::extractCode('http://localhost:8317/callback?code=kode-123&state=state-xyz')
        );
    }

    public function test_callback_prefers_pasted_state_over_stale_session_state(): void
    {
        // Simulasi kasus Bima: state di session berasal dari link login yang
        // lebih baru, tapi user menyelesaikan OAuth di tab lama.
        $freshAccount = $this->codexFile(false);
        $freshAccount['modtime'] = '2026-10-13T20:33:59+07:00';

        $client = \Mockery::mock(CliProxyApiClient::class)->makePartial();
        $client->shouldReceive('isAvailable')->andReturn(true);
        $client->shouldReceive('submitCallback')
            ->once()
            ->with('state-dari-url-lama', 'kode-abc')
            ->andReturn(true);
        $client->shouldReceive('waitForTokenRefresh')->once()->andReturn($freshAccount);
        $client->shouldReceive('waitForAccountEnabled')->once()->andReturn(true);
        $client->shouldReceive('forgetModelsCache')->once();
        $this->app->instance(CliProxyApiClient::class, $client);

        $response = $this->actingAs(User::factory()->create())
            ->withSession(['ai_provider_state.codex' => 'state-session-baru'])
            ->post('/ai-providers/codex/callback', [
                'callback_url' => 'http://localhost:8317/callback?code=kode-abc&state=state-dari-url-lama',
            ]);

        $response->assertRedirect(route('ai-providers.index'));
        $response->assertSessionHas('success');

        \Mockery::close();
    }

    public function test_callback_enables_previously_disconnected_account_after_token_refresh(): void
    {
        // Kasus nyata Bima 20:33: OAuth sukses (token refresh) tapi file akun
        // masih disabled — controller harus mengaktifkannya otomatis.
        $refreshedAccount = $this->codexFile(true);
        $refreshedAccount['modtime'] = '2026-10-13T20:33:59+07:00';

        $client = \Mockery::mock(CliProxyApiClient::class)->makePartial();
        $client->shouldReceive('isAvailable')->andReturn(true);
        $client->shouldReceive('submitCallback')->once()->andReturn(true);
        $client->shouldReceive('waitForTokenRefresh')->once()->andReturn($refreshedAccount);
        $client->shouldReceive('setAccountDisabled')->once()->with($refreshedAccount['name'], false)->andReturn(true);
        $client->shouldReceive('waitForAccountEnabled')->once()->andReturn(true);
        $client->shouldReceive('forgetModelsCache')->once();
        $this->app->instance(CliProxyApiClient::class, $client);

        $response = $this->actingAs(User::factory()->create())
            ->withSession(['ai_provider_state.codex' => 'state-123'])
            ->post('/ai-providers/codex/callback', [
                'callback_url' => 'http://localhost:8317/callback?code=kode-abc&state=state-123',
            ]);

        $response->assertRedirect(route('ai-providers.index'));
        $response->assertSessionHas('success', 'ChatGPT (Codex) berhasil terhubung.');

        \Mockery::close();
    }

    public function test_wait_for_token_refresh_detects_rewritten_file(): void
    {
        $old = $this->codexFile(true);
        $old['modtime'] = '2026-10-13T20:00:00+07:00';
        $new = $this->codexFile(true);
        $new['modtime'] = '2026-10-13T20:33:59+07:00';

        $calls = 0;
        Http::fake(function () use (&$calls, $old, $new) {
            $calls++;

            return Http::response(['files' => [$calls === 1 ? $old : $new]]);
        });

        $account = app(CliProxyApiClient::class)->waitForTokenRefresh('codex', 10, 1);

        $this->assertNotNull($account);
        $this->assertSame('2026-10-13T20:33:59+07:00', $account['modtime']);
    }

    public function test_wait_for_token_refresh_times_out_when_nothing_changes(): void
    {
        Http::fake([
            $this->mgmt('/auth-files') => Http::response(['files' => [$this->codexFile(true)]]),
        ]);

        // Tanpa modtime di respons, file lama yang tak berubah = bukan refresh.
        $this->assertNull(app(CliProxyApiClient::class)->waitForTokenRefresh('codex', 0));
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

    public function test_callback_does_not_claim_success_when_account_stays_disabled(): void
    {
        Http::fake([
            $this->mgmt('/oauth-callback') => Http::response(['status' => 'ok']),
            // Kode diterima gateway, tetapi akun tak pernah aktif
            // (mis. pertukaran kode gagal secara asinkron).
            $this->mgmt('/auth-files') => Http::response(['files' => [$this->codexFile(true)]]),
        ]);

        // waitForTokenRefresh default 60 detik terlalu lama untuk test;
        // pakai mock parsial agar polling langsung gagal.
        $client = \Mockery::mock(CliProxyApiClient::class)->makePartial();
        $client->shouldReceive('isAvailable')->andReturn(true);
        $client->shouldReceive('submitCallback')->once()->andReturn(true);
        $client->shouldReceive('waitForTokenRefresh')->once()->andReturn(null);
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

    private function googleFile(string $email, bool $disabled, string $modtime = '2026-11-15T10:00:00Z'): array
    {
        return ['id' => "antigravity-{$email}.json", 'name' => "antigravity-{$email}.json", 'provider' => 'antigravity', 'email' => $email, 'disabled' => $disabled, 'modtime' => $modtime];
    }

    public function test_disconnect_deletes_the_chosen_account_not_the_first_active_one(): void
    {
        $files = [$this->googleFile('lama@example.com', false), $this->googleFile('baru@example.com', false)];
        Http::fake(function (Request $request) use ($files) {
            return $request->method() === 'DELETE' ? Http::response(['status' => 'ok']) : Http::response(['files' => $files]);
        });

        $this->actingAs(User::factory()->create())
            ->post('/ai-providers/antigravity/disconnect', ['account' => 'antigravity-lama@example.com.json'])
            ->assertRedirect(route('ai-providers.index'))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'lama@example.com') && str_contains($m, 'dihapus'));

        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains(urldecode($r->url()), 'name=antigravity-lama@example.com.json'));
        Http::assertNotSent(fn ($r) => $r->method() === 'DELETE' && str_contains(urldecode($r->url()), 'baru@example.com'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/auth-files/status')); // tidak sekadar dinonaktifkan
    }

    public function test_new_login_is_recognised_among_many_accounts_and_old_ones_stay_off(): void
    {
        // Kasus 15 Nov: akun lama yang sudah diputus ikut aktif lagi saat akun baru disambungkan.
        $before = [$this->googleFile('lama@example.com', true), $this->googleFile('aktif@example.com', false)];
        $after = [...$before, $this->googleFile('baru@example.com', false, '2026-11-15T11:09:54Z')];
        $calls = 0;
        Http::fake(function () use (&$calls, $before, $after) {
            return Http::response(['files' => $calls++ === 0 ? $before : $after]);
        });

        $client = app(CliProxyApiClient::class);
        $snapshot = $client->accountSnapshot('antigravity');
        $account = $client->waitForTokenRefresh('antigravity', 5, 1, $snapshot);

        $this->assertSame('baru@example.com', $account['email']);
        $this->assertTrue($client->waitForAccountEnabled('antigravity', 0, 1, 'antigravity-baru@example.com.json'));
        $this->assertFalse($client->waitForAccountEnabled('antigravity', 0, 1, 'antigravity-lama@example.com.json'));
    }

    public function test_page_lists_each_active_account_with_its_own_disconnect_button(): void
    {
        config(['ai.cliproxy.api_key' => 'k']);
        $files = [$this->googleFile('satu@example.com', false), $this->googleFile('dua@example.com', false), $this->googleFile('mati@example.com', true)];
        Http::fake(fn () => Http::response(['files' => $files, 'data' => []]));

        $html = $this->actingAs(User::factory()->create())->get(route('ai-providers.index'))->assertOk()->getContent();
        $this->assertStringContainsString('value="antigravity-satu@example.com.json"', $html);
        $this->assertStringContainsString('value="antigravity-dua@example.com.json"', $html);
        $this->assertStringNotContainsString('value="antigravity-mati@example.com.json"', $html);
    }
}
