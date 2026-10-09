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

    public function test_model_only_form_rejects_browser_persona_and_saves_fixed_role_pack(): void
    {
        $this->configure();
        $this->catalog();
        $this->actingAs(User::factory()->create());
        $selection = json_encode(['provider' => 'test-provider', 'model' => 'model-one']);
        $page = $this->get('/agents/scrum-master/config')->assertOk()->assertSee('name="selection"', false)
            ->assertDontSee('name="instructions"', false)->assertDontSee('name="soul"', false)
            ->assertDontSee('name="skills[]"', false)->assertDontSee('name="provider"', false)
            ->assertDontSee('Editable role instructions')->assertDontSee('Curated skill');
        foreach (['provider', 'model', 'instructions', 'soul', 'skills', 'base_url', 'api_key', 'profile', 'policy'] as $field) {
            $this->putJson('/agents/scrum-master/config', ['config_version' => 0, 'selection' => $selection, $field => 'injected'])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->putJson('/agents/scrum-master/config', ['config_version' => 0, 'selection' => $selection])->assertOk();
        $pack = (new AgentConfiguration)->packs()['profiles']['scrum-master'];
        $this->assertDatabaseHas('agent_config_versions', ['provider' => 'test-provider', 'model' => 'model-one', 'instructions' => $pack['instructions'], 'soul' => $pack['soul']]);
        $this->getJson('/agents/scrum-master/config/history')->assertOk()->assertDontSee($pack['instructions'])->assertDontSee($pack['soul'])->assertDontSee('skills');
        $this->get('/agents/scrum-master/config')->assertDontSee($pack['instructions'])->assertDontSee($pack['soul']);
    }

    public function test_packaged_role_content_matches_native_profile_source(): void
    {
        $packs = (new AgentConfiguration)->packs()['profiles'];
        foreach (config('agents.profiles') as $role) {
            $slug = $role['slug'];
            $this->assertSame($packs[$slug]['soul'], $packs[$slug]['instructions']);
            $this->assertNotEmpty($packs[$slug]['skills']);
            $this->assertStringContainsString('Acceptance Criteria', $packs[$slug]['soul']);
            $this->assertStringContainsString('poin', $packs[$slug]['skill_body']);
            // CI need not mount a live Hermes profile; set this to verify deployment parity.
            $nativeRoot = getenv('SCRUMSPACE_NATIVE_PROFILES_DIR');
            if ($nativeRoot !== false && $nativeRoot !== '') {
                $profile = rtrim($nativeRoot, '/').'/'.$role['profile'];
                $this->assertSame(file_get_contents($profile.'/SOUL.md'), $packs[$slug]['soul']);
                $this->assertSame(file_get_contents($profile.'/skills/'.$packs[$slug]['skills'][0].'/SKILL.md'), $packs[$slug]['skill_body']);
            }
        }
    }

    public function test_model_pair_must_be_exactly_encoded_and_live_catalog_verified_even_on_restore(): void
    {
        $this->configure();
        $this->catalog();
        $this->actingAs(User::factory()->create());
        foreach (['invalid', '{}', '{"provider":"test-provider","model":"model-one","other":1}', '{"provider":"unproven","provider":"test-provider","model":"model-one"}', '{"provider":"unproven","model":"model-one"}', '{"provider":"test-provider","model":"unlisted"}'] as $selection) {
            $this->putJson('/agents/scrum-master/config', ['config_version' => 0, 'selection' => $selection])
                ->assertUnprocessable()->assertJsonValidationErrors('selection');
        }
        $this->assertDatabaseCount('agent_config_versions', 0);
        $selection = json_encode(['provider' => 'test-provider', 'model' => 'model-one']);
        $this->putJson('/agents/scrum-master/config', ['config_version' => 0, 'selection' => $selection])->assertOk();
        Http::swap(new Factory);
        Http::fake(['*' => Http::response([], 503)]);
        $this->putJson('/agents/scrum-master/config', ['config_version' => 1, 'selection' => $selection])->assertUnprocessable();
        $this->postJson('/agents/scrum-master/config/restore/1', ['config_version' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('agent_config_versions', 1);
    }

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
        $this->get('/agents/scrum-master/config')->assertOk()->assertSee('model-one')->assertDontSee('name="instructions"', false);
        $this->putJson('/agents/scrum-master/config', $payload)->assertStatus(409);
        $payload['config_version'] = 1;
        $payload['selection'] = json_encode(['provider' => 'test-provider', 'model' => 'model-two']);
        $this->putJson('/agents/scrum-master/config', $payload)->assertOk()->assertJsonPath('config_version', 2);
        $this->getJson('/agents/scrum-master/config/history')->assertOk()->assertJsonCount(2, 'versions')->assertJsonPath('versions.1.actor_id', $owner->id)->assertJsonPath('versions.1.model', 'model-one')->assertDontSee('instructions')->assertDontSee('soul')->assertDontSee('skills');
        $this->postJson('/agents/scrum-master/config/restore/1', ['config_version' => 2])->assertOk()->assertJsonPath('config_version', 3);
        $this->assertDatabaseCount('agent_config_versions', 3);
        $this->assertDatabaseHas('agent_config_versions', ['role' => 'scrum-master', 'config_version' => 3, 'model' => 'model-one']);
    }

    public function test_offline_catalog_cannot_save_unverified_model(): void
    {
        $this->configure();
        Http::fake(['*' => Http::response([], 503)]);
        $this->actingAs(User::factory()->create());
        $this->putJson('/agents/scrum-master/config', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('selection');
        $this->assertDatabaseCount('agent_config_versions', 0);
        $this->assertFalse((new HermesClient)->isAvailable());
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
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
        $payload['selection'] = json_encode(['provider' => 'unproven', 'model' => 'model-one']);
        $this->putJson('/agents/scrum-master/config', $payload)->assertUnprocessable()->assertJsonValidationErrors('selection');
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

    public function test_roles_can_share_a_catalog_verified_model_and_retain_independent_selections(): void
    {
        $this->configure();
        $this->configure('backend-developer');
        Http::fake([
            'http://scrum-master.test/api/model/options' => Http::response(['providers' => [['slug' => 'test-provider', 'available' => true, 'models' => ['model-one']]]]),
            'http://backend-developer.test/api/model/options' => Http::response(['providers' => [['slug' => 'test-provider', 'available' => true, 'models' => ['model-one', 'model-two']]]]),
        ]);
        $this->actingAs(User::factory()->create());
        $this->putJson('/agents/scrum-master/config', $this->payload())->assertOk();
        $this->putJson('/agents/backend-developer/config', $this->payload())->assertOk();
        foreach (['scrum-master', 'backend-developer'] as $role) {
            $this->assertDatabaseHas('agent_role_settings', ['role' => $role, 'config_version' => 1, 'active_model' => 'model-one']);
            $this->assertSame('model-one', (new AgentConfiguration)->current($role)['model']);
            $this->get('/agents/'.$role.'/config')->assertOk()->assertSee('model-one');
        }
        $this->assertDatabaseCount('agent_config_versions', 2);
    }

    public function test_migrated_schema_preserves_four_role_pointers_and_foreign_keys_without_unique_model_index(): void
    {
        $this->catalog();
        $this->actingAs(User::factory()->create());
        foreach (config('agents.profiles') as $position => $agent) {
            $role = $agent['slug'];
            $this->configure($role);
            $this->putJson('/agents/'.$role.'/config', [
                'config_version' => 0,
                'selection' => json_encode(['provider' => 'test-provider', 'model' => ['model-one', 'model-two', 'model-three', 'model-four'][$position]]),
            ])->assertOk();
        }
        $before = DB::table('agent_role_settings')->orderBy('role')->get()->toArray();
        $versions = DB::table('agent_config_versions')->orderBy('id')->get()->toArray();
        $this->assertCount(4, $before);
        $indexes = DB::select("PRAGMA index_list('agent_role_settings')");
        $this->assertNotContains('agent_role_settings_active_model_unique', array_column($indexes, 'name'));
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertEquals($before, DB::table('agent_role_settings')->orderBy('role')->get()->toArray());
        $this->assertEquals($versions, DB::table('agent_config_versions')->orderBy('id')->get()->toArray());
        DB::table('agent_role_settings')->where('role', 'backend-developer')->update(['active_model' => 'model-one']);
        $this->assertDatabaseHas('agent_role_settings', ['role' => 'backend-developer', 'active_model' => 'model-one']);
        $this->assertDatabaseHas('agent_role_settings', ['role' => 'scrum-master', 'active_model' => 'model-one']);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_distinct_models_remain_available_per_role(): void
    {
        $this->configure();
        $this->configure('backend-developer');
        $this->catalog();
        $this->actingAs(User::factory()->create());
        $this->putJson('/agents/scrum-master/config', $this->payload())->assertOk();
        $this->putJson('/agents/backend-developer/config', [
            'config_version' => 0,
            'selection' => json_encode(['provider' => 'test-provider', 'model' => 'model-two']),
        ])->assertOk();
        $this->assertSame('model-one', (new AgentConfiguration)->current('scrum-master')['model']);
        $this->assertSame('model-two', (new AgentConfiguration)->current('backend-developer')['model']);
    }

    public function test_auth_and_forbidden_configuration_fields_are_enforced(): void
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
        foreach (['provider', 'model', 'instructions', 'soul', 'skills', 'base_url', 'api_key', 'profile', 'policy'] as $field) {
            $this->putJson('/agents/scrum-master/config', [...$this->payload(), $field => 'forbidden'])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        Http::assertNothingSent();
        $this->configure();
        Http::swap(new Factory);
        $this->catalog();
        $this->putJson('/agents/scrum-master/config', $this->payload())->assertOk();
        $this->postJson('/agents/scrum-master/config/restore/1', ['config_version' => 1, 'instructions' => 'forbidden'])->assertUnprocessable()->assertJsonValidationErrors('instructions');
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
            $this->assertSame((new AgentConfiguration)->packs()['profiles']['scrum-master']['instructions'], $current['instructions']);
            $this->assertSame('scrumagent', $current['profile']);
            $this->getJson('/agents/scrum-master/config/history')->assertOk()->assertJsonCount(1, 'versions');
            try {
                DB::table('agent_config_versions')->where('role', 'scrum-master')->update(['instructions' => 'tampered']);
                $this->fail('Immutable history accepted UPDATE');
            } catch (QueryException) {
                $this->assertDatabaseHas('agent_config_versions', ['instructions' => (new AgentConfiguration)->packs()['profiles']['scrum-master']['instructions']]);
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
        $this->putJson('/agents/scrum-master/config', [...$this->payload(), 'selection' => json_encode(['provider' => 'test-provider', 'model' => 'blocked-model'])])->assertUnprocessable();
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
        return ['config_version' => 0, 'selection' => json_encode(['provider' => 'test-provider', 'model' => 'model-one'])];
    }
}
