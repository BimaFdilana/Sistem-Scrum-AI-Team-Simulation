<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AgentConfiguration;
use App\Services\HermesClient;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AgentConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_directory_links_fixed_role_editors_and_missing_schema_is_read_only(): void
    {
        Schema::dropIfExists('agent_dispatches');
        Schema::dropIfExists('agent_role_settings');
        Schema::dropIfExists('agent_config_versions');
        Http::fake();
        $this->actingAs(User::factory()->create());
        $this->get('/agents')->assertOk()->assertSee('/agents/scrum-master/config', false);
        foreach (config('agents.profiles') as $agent) {
            $this->get('/agents/'.$agent['slug'].'/config')->assertOk()->assertSee('Migrasi konfigurasi belum diterapkan');
        }
        $this->putJson('/agents/scrum-master/config', [])->assertStatus(503);
        $this->get('/agents/unknown/config')->assertNotFound();
    }

    public function test_verified_configuration_persists_history_conflicts_and_restore_creates_new_version(): void
    {
        $this->configure();
        $this->catalog();
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $payload = $this->payload();
        $this->putJson('/agents/scrum-master/config', $payload)->assertOk()->assertJsonPath('config_version', 1)->assertJsonPath('status', 'verified');
        $this->assertDatabaseHas('agent_role_settings', ['role' => 'scrum-master', 'config_version' => 1, 'active_model' => 'model-one']);
        $this->get('/agents/scrum-master/config')->assertOk()->assertSee('Editable instructions')->assertSee('SOUL');
        $this->putJson('/agents/scrum-master/config', $payload)->assertStatus(409);
        $payload['config_version'] = 1;
        $payload['instructions'] = 'Second instructions';
        $this->putJson('/agents/scrum-master/config', $payload)->assertOk()->assertJsonPath('config_version', 2);
        $this->getJson('/agents/scrum-master/config/history')->assertOk()->assertJsonCount(2, 'versions')->assertJsonPath('versions.1.actor_id', $owner->id)->assertJsonPath('versions.1.instructions', 'Editable instructions');
        $this->postJson('/agents/scrum-master/config/restore/1', ['config_version' => 2])->assertOk()->assertJsonPath('config_version', 3);
        $this->assertDatabaseCount('agent_config_versions', 3);
        $this->assertDatabaseHas('agent_config_versions', ['role' => 'scrum-master', 'config_version' => 2, 'instructions' => 'Second instructions']);
        $this->assertDatabaseHas('agent_config_versions', ['role' => 'scrum-master', 'config_version' => 3, 'instructions' => 'Editable instructions']);
    }

    public function test_offline_content_draft_and_restore_are_pending_and_cannot_claim_available(): void
    {
        $this->configure();
        Http::fake(['*' => Http::response([], 503)]);
        $this->actingAs(User::factory()->create());
        $draft = $this->payload();
        $draft['provider'] = '';
        $draft['model'] = 'scrum-master';
        $this->putJson('/agents/scrum-master/config', $draft)->assertOk()->assertJsonPath('status', 'pending');
        $draft['config_version'] = 1;
        $draft['instructions'] = 'Offline edited content';
        $this->putJson('/agents/scrum-master/config', $draft)->assertOk()->assertJsonPath('status', 'pending');
        $this->postJson('/agents/scrum-master/config/restore/1', ['config_version' => 2])->assertOk()->assertJsonPath('config_version', 3)->assertJsonPath('status', 'pending');
        $this->assertDatabaseHas('agent_config_versions', ['config_version' => 3, 'instructions' => 'Editable instructions']);
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['data' => [['id' => 'scrum-master']]])]);
        $this->assertFalse((new HermesClient)->isAvailable());
        Http::assertNothingSent();
    }

    public function test_catalog_get_only_allowlists_metadata_and_rejects_unavailable_or_unproven_provider(): void
    {
        $this->configure();
        Http::fake(['*/api/model/options' => Http::response(['providers' => [
            ['slug' => 'test-provider', 'name' => '<script>unsafe</script>', 'authenticated' => true, 'models' => ['model-one'], 'api_key' => 'catalog-secret', 'base_url' => 'http://private-runtime'],
            ['slug' => 'unproven', 'models' => ['model-one']],
            ['slug' => 'locked', 'authenticated' => true, 'available' => false, 'models' => ['model-one']],
        ], 'provider' => 'test-provider', 'model' => 'model-one', 'env' => 'private-env'])]);
        $this->actingAs(User::factory()->create());
        $response = $this->getJson('/agents/scrum-master/config/connectivity')->assertOk()->assertJsonPath('status', 'online')
            ->assertJsonPath('providers.0.available', true)->assertJsonPath('providers.1.available', false)->assertJsonPath('providers.2.models', [])
            ->assertJsonPath('capabilities.inference_checked', false)->assertJsonPath('capabilities.execution_enabled', false);
        $this->assertSame(['slug', 'name', 'available', 'models'], array_keys($response->json('providers.0')));
        $response->assertDontSee('catalog-secret')->assertDontSee('private-runtime')->assertDontSee('private-env');
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->url() === 'http://scrum-master.test/api/model/options' && $r->hasHeader('Authorization', 'Bearer private-test-key'));
        Http::assertSentCount(1);
        $payload = $this->payload();
        $payload['provider'] = 'unproven';
        $this->putJson('/agents/scrum-master/config', $payload)->assertUnprocessable()->assertJsonValidationErrors('model');
        $this->assertDatabaseCount('agent_config_versions', 0);
        $this->get('/agents/scrum-master/config')->assertOk()->assertDontSee('<script>unsafe</script>', false);
    }

    public function test_offline_catalog_does_not_fabricate_models_and_rejects_changes_without_saving(): void
    {
        $this->configure();
        Http::fake(['*' => Http::failedConnection()]);
        $this->actingAs(User::factory()->create());
        $this->getJson('/agents/scrum-master/config/connectivity')->assertOk()->assertJsonPath('status', 'offline')->assertJsonPath('providers', []);
        $this->get('/agents/scrum-master/config')->assertOk()->assertSee('offline')->assertDontSee('model-one');
        $this->putJson('/agents/scrum-master/config', $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('agent_role_settings', 0);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_duplicate_active_models_are_rejected_across_roles_and_endpoint_catalog_is_role_scoped(): void
    {
        $this->configure();
        $this->configure('backend-developer');
        Http::fake([
            'http://scrum-master.test/api/model/options' => Http::response(['providers' => [['slug' => 'test-provider', 'available' => true, 'models' => ['model-one']]]]),
            'http://backend-developer.test/api/model/options' => Http::response(['providers' => [['slug' => 'test-provider', 'available' => true, 'models' => ['model-one', 'model-two']]]]),
        ]);
        $this->actingAs(User::factory()->create());
        $this->putJson('/agents/scrum-master/config', $this->payload())->assertOk();
        $payload = $this->payload();
        $payload['skills'] = ['scrumspace-laravel-backend'];
        $this->putJson('/agents/backend-developer/config', $payload)->assertUnprocessable()->assertJsonValidationErrors('model');
        $payload['model'] = 'model-two';
        $this->putJson('/agents/backend-developer/config', $payload)->assertOk();
        $payload['skills'] = ['scrumspace-scrum-master'];
        $payload['config_version'] = 1;
        $this->putJson('/agents/scrum-master/config', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('agent_config_versions', 2);
    }

    public function test_auth_role_skills_and_endpoint_policy_boundaries_and_xss_escaping(): void
    {
        Http::fake();
        foreach (['/agents/scrum-master/config', '/agents/scrum-master/config/history', '/agents/scrum-master/config/connectivity'] as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
        $this->putJson('/agents/scrum-master/config', $this->payload())->assertUnauthorized();
        $this->postJson('/agents/scrum-master/config/restore/1', ['config_version' => 0])->assertUnauthorized();
        Http::assertNothingSent();
        $this->actingAs(User::factory()->create());
        $this->putJson('/agents/unknown/config', $this->payload())->assertNotFound();
        foreach (['../../private', 'scrumspace-laravel-qa', 'https://remote/skill'] as $skill) {
            $payload = $this->payload();
            $payload['skills'] = [$skill];
            $this->putJson('/agents/scrum-master/config', $payload)->assertUnprocessable()->assertJsonValidationErrors('skills.0');
        }
        foreach (['base_url', 'api_key', 'profile', 'policy'] as $field) {
            $this->putJson('/agents/scrum-master/config', [...$this->payload(), $field => 'forbidden'])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        Http::assertNothingSent();
        $this->configure();
        Http::swap(new Factory);
        $this->catalog();
        $payload = $this->payload();
        $payload['instructions'] = '<script>alert("XSS")</script>';
        $payload['soul'] = '<img src=x onerror=alert(1)>';
        $this->putJson('/agents/scrum-master/config', $payload)->assertOk();
        $this->get('/agents/scrum-master/config')->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(', false)->assertDontSee('<img src=x', false);
        $this->getJson('/agents/scrum-master/config/history')->assertDontSee('private-test-key')->assertDontSee('secret-catalog')->assertDontSee('private-catalog');
        $this->postJson('/agents/backend-developer/config/restore/1', ['config_version' => 0])->assertNotFound();
    }

    public function test_config_writes_require_csrf_outside_test_bypass(): void
    {
        $this->app->bind(PreventRequestForgery::class, function ($app) {
            return new class($app, $app['encrypter']) extends PreventRequestForgery
            {
                protected function runningUnitTests()
                {
                    return false;
                }
            };
        });
        Http::fake();
        $this->actingAs(User::factory()->create());
        $this->putJson('/agents/scrum-master/config', $this->payload())->assertStatus(419);
        $this->postJson('/agents/scrum-master/config/restore/1', ['config_version' => 0])->assertStatus(419);
        Http::assertNothingSent();
    }

    public function test_configuration_survives_database_reconnect_and_history_is_database_immutable(): void
    {
        $owner = User::factory()->create();
        $path = tempnam((string) getenv('TMPDIR'), 'stage2-restart-');
        $this->assertStringStartsWith('/home/sipua/.hermes/cache/scratch/stage2-agent-config-20261008/tmp/', $path);
        config(['database.connections.stage2_restart' => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true], 'database.default' => 'stage2_restart']);
        try {
            $migration = require database_path('migrations/2026_10_08_000001_create_agent_configuration_tables.php');
            $migration->up();
            $this->configure();
            $this->catalog();
            $this->actingAs($owner)->putJson('/agents/scrum-master/config', $this->payload())->assertOk();
            DB::purge('stage2_restart');
            $current = (new AgentConfiguration)->current('scrum-master');
            $this->assertSame('Editable instructions', $current['instructions']);
            $this->assertSame('scrumagent', $current['profile']);
            $this->getJson('/agents/scrum-master/config/history')->assertOk()->assertJsonCount(1, 'versions');
            try {
                DB::table('agent_config_versions')->where('role', 'scrum-master')->update(['instructions' => 'tampered']);
                $this->fail('Immutable history accepted UPDATE');
            } catch (QueryException) {
                $this->assertDatabaseHas('agent_config_versions', ['instructions' => 'Editable instructions']);
            }
            try {
                DB::table('agent_config_versions')->where('role', 'scrum-master')->delete();
                $this->fail('Immutable history accepted DELETE');
            } catch (QueryException) {
                $this->assertDatabaseCount('agent_config_versions', 1);
            }
            $migration->down();
            $this->assertFalse(Schema::hasTable('agent_config_versions'));
        } finally {
            DB::purge('stage2_restart');
            config(['database.default' => 'sqlite']);
            unlink($path);
        }
    }

    public function test_catalog_filters_model_entitlement_and_unsafe_labels_without_secret_details(): void
    {
        $this->configure();
        Http::fake(['*/api/model/options' => Http::response(['providers' => [
            ['slug' => 'test-provider', 'name' => 'Custom http://private.example/v1', 'authenticated' => true, 'models' => ['model-one', 'blocked-model', 'http://private.example/model'], 'unavailable_models' => ['blocked-model']],
            ['slug' => 'nous', 'authenticated' => true, 'free_tier_pending' => true, 'models' => ['model-two']],
        ]])]);
        $this->actingAs(User::factory()->create());
        $this->getJson('/agents/scrum-master/config/connectivity')->assertOk()->assertJsonPath('providers.0.models', ['model-one'])
            ->assertJsonPath('providers.1.available', false)->assertDontSee('private.example');
        $this->putJson('/agents/scrum-master/config', [...$this->payload(), 'model' => 'blocked-model'])->assertUnprocessable();
    }

    private function configure(string $role = 'scrum-master'): void
    {
        $prefix = $role === 'scrum-master' ? 'services.hermes' : 'services.agent_profiles.'.$role;
        config([$prefix.'.base_url' => 'http://'.$role.'.test/v1', $prefix.'.api_key' => 'private-test-key', $prefix.'.model' => $role]);
    }

    private function catalog(): void
    {
        Http::fake(['*/api/model/options' => Http::response(['providers' => [['slug' => 'test-provider', 'name' => 'Test Provider', 'available' => true, 'models' => ['model-one', 'model-two', 'model-three', 'model-four'], 'api_key' => 'secret-catalog', 'base_url' => 'private-catalog']]])]);
    }

    private function payload(): array
    {
        return ['config_version' => 0, 'provider' => 'test-provider', 'model' => 'model-one', 'instructions' => 'Editable instructions', 'soul' => 'Editable SOUL', 'skills' => ['scrumspace-scrum-master']];
    }
}
