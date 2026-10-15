<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GithubSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function user(): User
    {
        return User::factory()->create();
    }

    public function test_settings_page_requires_auth(): void
    {
        $this->get('/settings/github')->assertRedirect('/login');
    }

    public function test_settings_page_shows_disconnected_state(): void
    {
        $response = $this->actingAs($this->user())->get('/settings/github');

        $response->assertOk();
        $response->assertSee('Belum terhubung');
    }

    public function test_settings_page_shows_connected_state(): void
    {
        Setting::put('github_pat', 'ghp_valid123');
        Http::fake([
            'api.github.com/user' => Http::response(['login' => 'bimapo', 'name' => 'Bima']),
        ]);

        $response = $this->actingAs($this->user())->get('/settings/github');

        $response->assertOk();
        $response->assertSee('@bimapo');
        $response->assertSee('Terhubung');
    }

    public function test_save_valid_token(): void
    {
        Http::fake([
            'api.github.com/user' => Http::response(['login' => 'bimapo']),
        ]);

        $response = $this->actingAs($this->user())->post('/settings/github', [
            'pat' => 'ghp_validtoken1234567890',
        ]);

        $response->assertRedirect(route('settings.github.edit'));
        $response->assertSessionHas('success');
        $this->assertSame('ghp_validtoken1234567890', Setting::get('github_pat'));
    }

    public function test_save_invalid_token_rejected(): void
    {
        Http::fake([
            'api.github.com/user' => Http::response(['message' => 'Bad credentials'], 401),
        ]);

        $response = $this->actingAs($this->user())->post('/settings/github', [
            'pat' => 'ghp_salah12345678901234',
        ]);

        $response->assertSessionHas('error');
        $this->assertNull(Setting::get('github_pat'));
    }

    public function test_disconnect_removes_token(): void
    {
        Setting::put('github_pat', 'ghp_valid123');

        $response = $this->actingAs($this->user())->post('/settings/github/disconnect');

        $response->assertRedirect(route('settings.github.edit'));
        $this->assertNull(Setting::get('github_pat'));
    }

    public function test_ajax_test_connection(): void
    {
        Http::fake([
            'api.github.com/user' => Http::response(['login' => 'bimapo']),
        ]);

        $response = $this->actingAs($this->user())->postJson('/settings/github/test', [
            'pat' => 'ghp_test12345678901234',
        ]);

        $response->assertOk();
        $response->assertJson(['ok' => true, 'login' => 'bimapo']);
    }
}
