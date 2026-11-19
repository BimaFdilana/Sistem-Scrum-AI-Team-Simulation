<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\AntigravityClient;
use App\Services\AntigravityLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class AiModelPageTest extends TestCase
{
    use RefreshDatabase;

    private function account(bool $loggedIn = true): void
    {
        $models = $loggedIn ? [
            ['id' => 'gemini-3.8-flash-medium', 'name' => 'Gemini 3.8 Flash (Medium)'],
            ['id' => 'claude-opus-4-6-thinking', 'name' => 'Claude Opus 4.6 (Thinking)'],
        ] : [];
        $client = Mockery::mock(AntigravityClient::class)->makePartial();
        $client->shouldReceive('models')->andReturn($models);
        $client->shouldReceive('isInstalled')->andReturn(true);
        $client->shouldReceive('version')->andReturn('1.5.12');
        $this->app->instance(AntigravityClient::class, $client);
    }

    public function test_page_shows_login_status_and_model_choices(): void
    {
        $this->account();

        $this->actingAs(User::factory()->create())
            ->get(route('ai-model.index'))
            ->assertOk()
            ->assertSee('Terhubung')
            ->assertSee('versi 1.5.12')
            ->assertSee('Claude Opus 4.6 (Thinking)')
            ->assertSee('Ikuti model utama');
    }

    public function test_page_without_login_asks_to_connect_google(): void
    {
        $this->account(false);

        $this->actingAs(User::factory()->create())
            ->get(route('ai-model.index'))
            ->assertOk()
            ->assertSee('Hubungkan akun Google')
            ->assertSee('Hubungkan akun Google dulu');
    }

    public function test_old_providers_url_redirects_to_ai_model(): void
    {
        $this->actingAs(User::factory()->create())->get('/ai-providers')->assertRedirect('/ai-model');
    }

    public function test_saving_global_and_role_models(): void
    {
        $this->account();

        $this->actingAs(User::factory()->create())
            ->post(route('ai-model.models.update'), [
                'active_model' => 'gemini-3.8-flash-medium',
                'role_models' => ['qa-engineer' => 'claude-opus-4-6-thinking', 'scrum-master' => ''],
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame('gemini-3.8-flash-medium', Setting::get('active_model'));
        $this->assertSame('claude-opus-4-6-thinking', Setting::get('role_model.qa-engineer'));
        $this->assertNull(Setting::get('role_model.scrum-master'));
    }

    public function test_unknown_model_is_rejected(): void
    {
        $this->account();

        $this->actingAs(User::factory()->create())
            ->post(route('ai-model.models.update'), ['active_model' => 'model-palsu'])
            ->assertSessionHasErrors('active_model');

        $this->assertNull(Setting::get('active_model'));
    }

    public function test_login_code_is_rejected_when_session_is_not_waiting(): void
    {
        $login = Mockery::mock(AntigravityLogin::class);
        $login->shouldReceive('submitCode')->andThrow(new \InvalidArgumentException('Sesi login ini tidak sedang menunggu kode. Mulai login ulang.'));
        $this->app->instance(AntigravityLogin::class, $login);

        $this->actingAs(User::factory()->create())
            ->postJson(route('ai-model.session.code', ['session' => '0b8f6f7e-1111-4222-8333-444455556666']), ['code' => '4/abc'])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    public function test_guest_cannot_open_the_page(): void
    {
        $this->get(route('ai-model.index'))->assertRedirect();
        $this->getJson(route('ai-model.quota'))->assertUnauthorized();
        $this->getJson(route('ai-model.server'))->assertUnauthorized();
    }

    public function test_quota_endpoint_returns_the_account_quota(): void
    {
        $quota = ['groups' => [['name' => 'Gemini Models', 'models' => 'Gemini 3.8 Flash', 'buckets' => [
            ['window' => '5h', 'remaining' => 0.42, 'reset_at' => '2026-11-20T00:45:43Z'],
        ]]], 'error' => null, 'fetched_at' => '2026-11-19T20:00:00+00:00'];
        $client = Mockery::mock(AntigravityClient::class);
        $client->shouldReceive('accountQuota')->with(true)->once()->andReturn($quota);
        $this->app->instance(AntigravityClient::class, $client);

        $this->actingAs(User::factory()->create())
            ->getJson(route('ai-model.quota', ['fresh' => 1]))
            ->assertOk()
            ->assertJsonPath('groups.0.buckets.0.remaining', 0.42);
    }

    public function test_server_endpoint_reports_disk_memory_cpu_and_network(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson(route('ai-model.server'))
            ->assertOk()
            ->assertJsonStructure(['disk' => ['total', 'used', 'free', 'percent'], 'memory', 'cpu' => ['cores', 'percent', 'load'], 'network', 'uptime', 'sampled_at']);
    }

    public function test_polling_server_metrics_does_not_block_logout(): void
    {
        $login = Mockery::mock(AntigravityLogin::class);
        $login->shouldReceive('startLogout')->once()->andReturn('0b8f6f7e-1111-4222-8333-444455556666');
        $this->app->instance(AntigravityLogin::class, $login);
        $user = User::factory()->create();

        for ($i = 0; $i < 12; $i++) {
            $this->actingAs($user)->getJson(route('ai-model.server'))->assertOk();
        }

        $this->actingAs($user)
            ->postJson(route('ai-model.logout'))
            ->assertOk()
            ->assertJsonPath('session', '0b8f6f7e-1111-4222-8333-444455556666');
    }

    public function test_missing_token_file_overrides_a_stale_logged_in_cache(): void
    {
        $dir = storage_path('framework/testing/agy-token-'.uniqid());
        mkdir($dir, 0777, true);
        config(['ai.antigravity.token_file' => $dir.'/antigravity-oauth-token']);
        Cache::put('antigravity:models', [['id' => 'gemini-3.8-flash-high', 'name' => 'Gemini 3.8 Flash (High)']], 600);
        $client = new AntigravityClient;

        try {
            touch($dir.'/antigravity-oauth-token');
            $this->assertCount(1, $client->models());

            unlink($dir.'/antigravity-oauth-token');
            $this->assertSame([], $client->models());
            $this->assertFalse($client->status()['logged_in']);
            $this->assertFalse(Cache::has('antigravity:models'));
        } finally {
            @unlink($dir.'/antigravity-oauth-token');
            rmdir($dir);
        }
    }

    public function test_page_has_quota_and_server_cards(): void
    {
        $this->account();

        $this->actingAs(User::factory()->create())
            ->get(route('ai-model.index'))
            ->assertSee('Sisa kuota')
            ->assertSee('Sumber daya VPS');
    }
}
