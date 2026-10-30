<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiKeyManagementTest extends TestCase
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

    private function v8(string $path): string
    {
        return 'http://127.0.0.1:8317/v8/management'.$path;
    }

    private function v0(string $path): string
    {
        return 'http://127.0.0.1:8317/v0/management'.$path;
    }

    private function fakePage(array $apiKeys): void
    {
        Http::fake([
            $this->v0('/auth-files') => Http::response(['files' => []]),
            'http://127.0.0.1:8317/v1/models' => Http::response(['data' => []]),
            $this->v8('/config') => Http::response([]),
            $this->v8('/config/api-keys') => Http::response($apiKeys),
            $this->v8('/config/api-keys/*') => Http::response(['status' => 'ok']),
        ]);
    }

    public function test_index_masks_api_keys_and_never_shows_full_key(): void
    {
        $this->fakePage([
            'gemini' => [
                ['name' => 'gemini-utama', 'keys' => [['api-key' => 'AIzaSy-SECRETKEY-1234', 'weight' => 1]]],
            ],
        ]);

        $response = $this->actingAs(User::factory()->create())->get('/ai-providers');

        $response->assertOk();
        $response->assertSee('AIza…1234');
        $response->assertDontSee('AIzaSy-SECRETKEY-1234');
    }

    public function test_store_account_puts_new_group_to_v8(): void
    {
        $this->fakePage(['gemini' => []]);

        $response = $this->actingAs(User::factory()->create())
            ->post('/ai-providers/api-keys', [
                'provider' => 'gemini',
                'group' => 'gemini-utama',
                'api_key' => 'test-key-12345678',
            ]);

        $response->assertRedirect(route('ai-providers.index'));
        $response->assertSessionHas('success');

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && $request->url() === $this->v8('/config/api-keys/gemini')
                && $request->data()[0]['name'] === 'gemini-utama'
                && $request->data()[0]['keys'][0]['api-key'] === 'test-key-12345678'
                && $request->data()[0]['keys'][0]['weight'] === 1;
        });
    }

    public function test_store_account_for_custom_provider_requires_base_url_and_model(): void
    {
        $this->fakePage([]);

        $response = $this->actingAs(User::factory()->create())
            ->post('/ai-providers/api-keys', [
                'provider' => 'openai-compatibility',
                'group' => 'mimo-1',
                'api_key' => 'test-key-12345678',
            ]);

        $response->assertSessionHasErrors(['base_url', 'model_name']);
        $this->assertArrayNotHasKey('api_key', session('_old_input', []));
        Http::assertNotSent(function ($request) {
            return $request->method() === 'PUT';
        });
    }

    public function test_store_account_rejects_duplicate_group_name(): void
    {
        $this->fakePage([
            'gemini' => [
                ['name' => 'gemini-utama', 'keys' => [['api-key' => 'k1', 'weight' => 1]]],
            ],
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->post('/ai-providers/api-keys', [
                'provider' => 'gemini',
                'group' => 'gemini-utama',
                'api_key' => 'test-key-12345678',
            ]);

        $response->assertSessionHas('error');
        Http::assertNotSent(function ($request) {
            return $request->method() === 'PUT';
        });
    }

    public function test_store_key_appends_to_existing_group(): void
    {
        $this->fakePage([
            'gemini' => [
                ['name' => 'gemini-utama', 'keys' => [['api-key' => 'k1', 'weight' => 1]]],
            ],
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->post('/ai-providers/api-keys/gemini/gemini-utama/keys', [
                'api_key' => 'k2-12345678',
            ]);

        $response->assertRedirect(route('ai-providers.index'));
        $response->assertSessionHas('success');

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && $request->url() === $this->v8('/config/api-keys/gemini')
                && count($request->data()[0]['keys']) === 2
                && $request->data()[0]['keys'][1]['api-key'] === 'k2-12345678';
        });
    }

    public function test_destroy_key_removes_only_that_entry(): void
    {
        $this->fakePage([
            'gemini' => [
                ['name' => 'gemini-utama', 'keys' => [
                    ['api-key' => 'k1', 'weight' => 1],
                    ['api-key' => 'k2', 'weight' => 1],
                ]],
            ],
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->delete('/ai-providers/api-keys/gemini/gemini-utama/keys/0');

        $response->assertRedirect(route('ai-providers.index'));
        $response->assertSessionHas('success');

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && count($request->data()[0]['keys']) === 1
                && $request->data()[0]['keys'][0]['api-key'] === 'k2';
        });
    }

    public function test_destroying_last_key_removes_the_account(): void
    {
        $this->fakePage([
            'gemini' => [
                ['name' => 'gemini-utama', 'keys' => [['api-key' => 'k1', 'weight' => 1]]],
            ],
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->delete('/ai-providers/api-keys/gemini/gemini-utama/keys/0');

        $response->assertRedirect(route('ai-providers.index'));
        $response->assertSessionHas('success');

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && $request->url() === $this->v8('/config/api-keys/gemini')
                && $request->data() === [];
        });
    }

    public function test_destroy_account_removes_the_group(): void
    {
        $this->fakePage([
            'gemini' => [
                ['name' => 'g1', 'keys' => [['api-key' => 'k1', 'weight' => 1]]],
                ['name' => 'g2', 'keys' => [['api-key' => 'k2', 'weight' => 1]]],
            ],
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->delete('/ai-providers/api-keys/gemini/g1');

        $response->assertRedirect(route('ai-providers.index'));
        $response->assertSessionHas('success');

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && count($request->data()) === 1
                && $request->data()[0]['name'] === 'g2';
        });
    }

    public function test_gateway_unreachable_shows_error_without_writing(): void
    {
        Http::fake([
            $this->v8('/config/api-keys') => Http::response(null, 500),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->post('/ai-providers/api-keys', [
                'provider' => 'gemini',
                'group' => 'gemini-utama',
                'api_key' => 'test-key-12345678',
            ]);

        $response->assertSessionHas('error');
        Http::assertNotSent(function ($request) {
            return $request->method() === 'PUT';
        });
    }

    public function test_tabs_render_with_provider_tab_active_by_default(): void
    {
        $this->fakePage(['gemini' => []]);

        $response = $this->actingAs(User::factory()->create())->get('/ai-providers');

        $response->assertOk();
        $response->assertSee('data-tab-target="providers"', false);
        $response->assertSee('data-tab-target="api-keys"', false);
        $response->assertSee('id="tab-panel-providers" class="tab-panel is-active"', false);
        $response->assertDontSee('id="tab-panel-api-keys" class="tab-panel is-active"');
    }

    public function test_api_key_action_redirect_opens_api_key_tab(): void
    {
        $this->fakePage(['gemini' => []]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/ai-providers/api-keys', [
            'provider' => 'gemini',
            'group' => 'gemini-utama',
            'api_key' => 'test-key-12345678',
        ]);

        $response->assertSessionHas('api_keys_tab', true);

        $response = $this->actingAs($user)
            ->withSession(['api_keys_tab' => true])
            ->get('/ai-providers');

        $response->assertOk();
        $response->assertSee('id="tab-panel-api-keys" class="tab-panel is-active"', false);
        $response->assertDontSee('id="tab-panel-providers" class="tab-panel is-active"');
    }
}
