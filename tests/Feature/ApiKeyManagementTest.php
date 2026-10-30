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

        $response->assertSessionHasErrors(['base_url', 'models_text']);
        $this->assertArrayNotHasKey('api_key', session('_old_input', []));
        Http::assertNotSent(function ($request) {
            return $request->method() === 'PUT';
        });
    }

    public function test_store_account_puts_many_models_from_textarea(): void
    {
        $this->fakePage(['openai-compatibility' => []]);

        $response = $this->actingAs(User::factory()->create())
            ->post('/ai-providers/api-keys', [
                'provider' => 'openai-compatibility',
                'group' => 'agg-1',
                'api_key' => 'test-key-12345678',
                'base_url' => 'https://example.com/v1',
                'models_text' => "kimi-k3\ndeepseek-v4-pro\nmimo-v2.6-pro\n",
            ]);

        $response->assertRedirect(route('ai-providers.index'));
        $response->assertSessionHas('success');

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && $request->url() === $this->v8('/config/api-keys/openai-compatibility')
                && $request->data()[0]['base-url'] === 'https://example.com/v1'
                && $request->data()[0]['models'] === [
                    ['name' => 'kimi-k3'],
                    ['name' => 'deepseek-v4-pro'],
                    ['name' => 'mimo-v2.6-pro'],
                ];
        });
    }

    public function test_store_account_supports_alias_syntax_in_models_textarea(): void
    {
        $this->fakePage(['openai-compatibility' => []]);

        $this->actingAs(User::factory()->create())
            ->post('/ai-providers/api-keys', [
                'provider' => 'openai-compatibility',
                'group' => 'agg-1',
                'api_key' => 'test-key-12345678',
                'base_url' => 'https://example.com/v1',
                'models_text' => "kimi-k3|Kimi K3\ndeepseek-v4-pro",
            ]);

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && $request->data()[0]['models'] === [
                    ['name' => 'kimi-k3', 'alias' => 'Kimi K3'],
                    ['name' => 'deepseek-v4-pro'],
                ];
        });
    }

    public function test_store_account_rejects_invalid_model_line_without_writing(): void
    {
        $this->fakePage(['openai-compatibility' => []]);

        $response = $this->actingAs(User::factory()->create())
            ->post('/ai-providers/api-keys', [
                'provider' => 'openai-compatibility',
                'group' => 'agg-1',
                'api_key' => 'test-key-12345678',
                'base_url' => 'https://example.com/v1',
                'models_text' => "kimi-k3\nbad model!",
            ]);

        $response->assertSessionHasErrors(['models_text']);
        Http::assertNotSent(function ($request) {
            return $request->method() === 'PUT';
        });
    }

    public function test_update_models_replaces_group_models_and_preserves_keys(): void
    {
        $this->fakePage([
            'openai-compatibility' => [
                [
                    'name' => 'agg-1',
                    'keys' => [['api-key' => 'k1', 'weight' => 1]],
                    'base-url' => 'https://example.com/v1',
                    'models' => [['name' => 'mimo-v2.5-pro']],
                ],
            ],
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->post('/ai-providers/api-keys/openai-compatibility/agg-1/models', [
                'models_text' => "kimi-k3\nmimo-v2.6-pro",
            ]);

        $response->assertRedirect(route('ai-providers.index'));
        $response->assertSessionHas('success');

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && $request->url() === $this->v8('/config/api-keys/openai-compatibility')
                && $request->data()[0]['name'] === 'agg-1'
                && $request->data()[0]['keys'][0]['api-key'] === 'k1'
                && $request->data()[0]['base-url'] === 'https://example.com/v1'
                && $request->data()[0]['models'] === [
                    ['name' => 'kimi-k3'],
                    ['name' => 'mimo-v2.6-pro'],
                ];
        });
    }

    public function test_update_models_rejects_invalid_line_without_writing(): void
    {
        $this->fakePage([
            'openai-compatibility' => [
                ['name' => 'agg-1', 'keys' => [['api-key' => 'k1', 'weight' => 1]]],
            ],
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->post('/ai-providers/api-keys/openai-compatibility/agg-1/models', [
                'models_text' => "kimi-k3\nbad model!",
            ]);

        $response->assertSessionHasErrors(['models_text']);
        Http::assertNotSent(function ($request) {
            return $request->method() === 'PUT';
        });
    }

    public function test_edit_models_textarea_prefills_existing_models_with_alias(): void
    {
        $this->fakePage([
            'openai-compatibility' => [
                [
                    'name' => 'agg-1',
                    'keys' => [['api-key' => 'test-key-12345678', 'weight' => 1]],
                    'base-url' => 'https://example.com/v1',
                    'models' => [['name' => 'kimi-k3', 'alias' => 'Kimi K3'], ['name' => 'deepseek-v4-pro']],
                ],
            ],
        ]);

        $response = $this->actingAs(User::factory()->create())->get('/ai-providers');

        $response->assertOk();
        $response->assertSee('kimi-k3|Kimi K3', false);
    }

    public function test_registry_recognises_deepseek_glm_and_kimi_families(): void
    {
        $providers = config('ai.model_providers');

        $this->assertSame(['deepseek'], $providers['deepseek']['id']);
        $this->assertSame(['glm'], $providers['glm']['id']);
        $this->assertSame(['kimi'], $providers['kimi']['id']);
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

    public function test_sections_render_active_and_connect_without_tabs(): void
    {
        $this->fakePage(['gemini' => []]);

        $response = $this->actingAs(User::factory()->create())->get('/ai-providers');

        $response->assertOk();
        $response->assertSee('Provider aktif');
        $response->assertSee('Hubungkan Provider');
        $response->assertSee('Google Gemini');
        $response->assertSee('api-key-dialog');
        $response->assertDontSee('tab-panel');
        $response->assertDontSee('data-tab-target');
    }

    public function test_failed_create_reopens_dialog(): void
    {
        $this->fakePage(['gemini' => []]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/ai-providers/api-keys', [
            'provider' => 'openai-compatibility',
            'group' => 'agg-1',
            'api_key' => 'test-key-12345678',
        ]);

        $response->assertSessionHas('api_key_dialog', true);

        $response = $this->actingAs($user)
            ->withSession(['api_key_dialog' => true])
            ->get('/ai-providers');

        $response->assertOk();
        $response->assertSee('id="api-key-dialog" open', false);
    }

    public function test_gateway_rejection_surfaces_reason_and_redacts_key(): void
    {
        Http::fake([
            $this->v0('/auth-files') => Http::response(['files' => []]),
            'http://127.0.0.1:8317/v1/models' => Http::response(['data' => []]),
            $this->v8('/config') => Http::response([]),
            $this->v8('/config/api-keys') => Http::response(['openai-compatibility' => []]),
            $this->v8('/config/api-keys/openai-compatibility') => Http::response(['error' => 'key test-key-12345678 ditolak gateway'], 400),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->post('/ai-providers/api-keys', [
                'provider' => 'openai-compatibility',
                'group' => 'agg-1',
                'api_key' => 'test-key-12345678',
                'base_url' => 'https://example.com/v1',
                'models_text' => "kimi-k3\nmimo-v2.6-pro",
            ]);

        $response->assertSessionHas('error');
        $response->assertSessionHas('api_key_dialog', true);
        $response->assertSessionHasInput('models_text', "kimi-k3\nmimo-v2.6-pro");

        $error = (string) session('error');
        $this->assertStringContainsString('ditolak gateway', $error);
        $this->assertStringNotContainsString('test-key-12345678', $error);
    }

    public function test_form_field_styles_exist_in_workspace_css(): void
    {
        $css = file_get_contents(public_path('css/workspace.css'));

        $this->assertStringContainsString('.form-panel{', $css);
        $this->assertStringContainsString('.form-field{', $css);
        $this->assertStringContainsString('.form-field input,.form-field select,.form-field textarea{', $css);
        $this->assertStringContainsString('.form-hint{', $css);
    }
}
