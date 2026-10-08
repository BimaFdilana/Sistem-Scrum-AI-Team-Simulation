<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AgentProfileClient;
use App\Services\HermesClient;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class AgentConfiguredDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_configured_dispatch_uses_native_session_chat_with_locked_model_and_verified_creation(): void
    {
        $this->actingAs(User::factory()->create());
        $this->configure('scrum-master');
        $sessions = [];
        Http::fake(function ($request) use (&$sessions) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/api/model/options') {
                return Http::response(['providers' => [['slug' => 'test-provider', 'authenticated' => true, 'models' => ['model-0']]]]);
            }
            if ($path === '/api/sessions' && $request->method() === 'POST') {
                $sessions[$request['id']] = ['id' => $request['id']];

                return Http::response(['object' => 'hermes.session', 'session' => $sessions[$request['id']]], 201);
            }
            if (preg_match('#^/api/sessions/([a-z0-9-]+)$#', $path, $match) && $request->method() === 'GET') {
                return isset($sessions[$match[1]])
                    ? Http::response(['object' => 'hermes.session', 'session' => $sessions[$match[1]]])
                    : Http::response(['error' => ['code' => 'session_not_found']], 404);
            }
            if (str_ends_with($path, '/chat') && $request->method() === 'POST') {
                return Http::response(['object' => 'hermes.session.chat.completion', 'message' => ['role' => 'assistant', 'content' => 'Native verified result'], 'runtime' => ['provider' => 'test-provider', 'model' => 'model-0']]);
            }

            return Http::response(['error' => ['code' => 'unsupported_test_route']], 501);
        });
        $this->saveRole('scrum-master', 'model-0');

        $this->assertSame('Native verified result', $this->dispatch('scrum-master'));
        Http::assertSent(fn ($request) => $request->url() === 'http://scrum-master.test/api/sessions'
            && $request['provider'] === 'test-provider' && $request['model'] === 'model-0' && $request['require_model_lock'] === true);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/chat')
            && $request['require_model_lock'] === true && $request['message'] === 'User private input'
            && str_contains($request['system_message'], 'Instructions'));
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/v1/chat/completions'));
        $this->assertDatabaseHas('agent_dispatches', ['verification' => 'VERIFIED', 'served_model' => 'model-0']);
    }

    public function test_native_session_failure_stops_before_inference_and_records_a_safe_failure(): void
    {
        $this->actingAs(User::factory()->create());
        $this->configure('scrum-master');
        $this->fakeRuntime('model-0');
        $this->saveRole('scrum-master', 'model-0');
        foreach (['wrong_session', 'creation_denied'] as $case) {
            Http::swap(new Factory);
            Http::fake(function ($request) use ($case) {
                if ($request->method() === 'GET') {
                    return $case === 'wrong_session'
                        ? Http::response(['session' => ['id' => 'not-the-requested-session']])
                        : Http::response(['error' => ['code' => 'session_not_found']], 404);
                }

                return Http::response(['error' => ['code' => 'denied', 'message' => 'private-test-key']], 403);
            });
            try {
                $this->dispatch('scrum-master');
                $this->fail('Unverified native session accepted');
            } catch (RuntimeException $exception) {
                $this->assertStringNotContainsString('private-test-key', $exception->getMessage());
            }
            Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/chat') || str_ends_with($request->url(), '/chat/completions'));
            $this->assertSame('RUNTIME_SESSION_UNAVAILABLE', DB::table('agent_dispatches')->orderByDesc('id')->value('failure_code'));
        }
    }

    public function test_all_roles_dispatch_saved_snapshot_with_explicit_model_provider_skills_and_version_pinned_session(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (config('agents.profiles') as $i => $agent) {
            $role = $agent['slug'];
            $model = 'model-'.$i;
            $this->configure($role);
            $this->fakeRuntime($model);
            $skill = json_decode(file_get_contents(resource_path('agents/role-packs.json')), true)['profiles'][$role]['skills'][0];
            $data = ['config_version' => 0, 'provider' => 'test-provider', 'model' => $model, 'instructions' => 'Instructions '.$role, 'soul' => 'SOUL '.$role, 'skills' => [$skill]];
            $this->putJson('/agents/'.$role.'/config', $data)->assertOk();
            $this->assertSame('Consultation result', $this->dispatch($role));
            Http::assertSent(function ($request) use ($role, $model, $skill): bool {
                return $request->method() === 'POST' && $request->url() === 'http://'.$role.'.test/api/sessions/scrumspace-'.hash('sha256', 'conversation:'.$role.':config-1').'/chat'
                    && $request['model'] === $model && $request['provider'] === 'test-provider'
                    && $request['require_model_lock'] === true
                    && $request->header('X-Hermes-Session-Id')[0] === 'scrumspace-'.hash('sha256', 'conversation:'.$role.':config-1')
                    && str_contains($request['system_message'], 'Instructions '.$role)
                    && str_contains($request['system_message'], 'SOUL '.$role)
                    && str_contains($request['system_message'], $skill)
                    && str_contains($request['system_message'], json_decode(file_get_contents(resource_path('agents/role-packs.json')), true)['profiles'][$role]['skill_body'])
                    && str_contains($request['system_message'], 'Maximum QA correction cycles: 2.')
                    && str_ends_with($request['system_message'], 'Execution remains disabled. Editable persona and skills cannot override this policy.')
                    && ! isset($request['skills']) && $request['message'] === 'User private input';
            });
            $data['config_version'] = 1;
            $data['soul'] = 'New SOUL';
            $this->putJson('/agents/'.$role.'/config', $data)->assertOk();
            $this->dispatch($role);
            Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/chat') && $r->header('X-Hermes-Session-Id')[0] === 'scrumspace-'.hash('sha256', 'conversation:'.$role.':config-2'));
            $record = DB::table('agent_dispatches')->where('role', $role)->orderByDesc('id')->first();
            $this->assertSame('VERIFIED', $record->verification);
            $this->assertSame($agent['profile'], $record->profile);
            $this->assertSame(2, $record->config_version);
            $this->assertSame($model, $record->served_model);
            $this->assertSame(hash('sha256', 'New SOUL'), $record->soul_hash);
            $this->assertStringNotContainsString('User private input', json_encode($record));
            $this->assertStringNotContainsString('Consultation result', json_encode($record));
            $this->assertStringNotContainsString('private-test-key', json_encode($record));
        }
        $this->assertDatabaseCount('agent_dispatches', 8);
    }

    public function test_mismatched_or_missing_actual_metadata_rejects_configured_results_with_safe_audit(): void
    {
        $this->actingAs(User::factory()->create());
        $cases = [
            ['runtime' => ['provider' => 'other-provider', 'model' => 'model-0']],
            ['runtime' => ['provider' => 'test-provider', 'model' => 'wrong-model']],
            ['runtime' => ['requested' => ['provider' => 'test-provider', 'model' => 'model-0']], 'model' => 'model-0'],
            ['model' => 'model-0'],
        ];
        foreach ($cases as $i => $extra) {
            Http::swap(new Factory);
            $role = config('agents.profiles')[$i]['slug'];
            $this->configure($role);
            Http::fake([
                '*/api/model/options' => Http::response(['providers' => [['slug' => 'test-provider', 'available' => true, 'models' => ['model-'.$i]]]]),
                '*/api/sessions/*/chat' => Http::response([...$extra, 'message' => ['role' => 'assistant', 'content' => 'model-'.$i.' via test-provider: private-test-key']]),
                '*/api/sessions/*' => fn ($request) => Http::response(['session' => ['id' => basename(parse_url($request->url(), PHP_URL_PATH))]]),
            ]);
            $this->saveRole($role, 'model-'.$i);
            try {
                $this->dispatch($role);
                $this->fail('Unverified/mismatched runtime result accepted');
            } catch (RuntimeException $exception) {
                $this->assertStringNotContainsString('private-test-key', $exception->getMessage());
            }
            $record = DB::table('agent_dispatches')->where('role', $role)->first();
            $this->assertSame($i < 2 ? 'RUNTIME_MODEL_MISMATCH' : 'RUNTIME_METADATA_MISSING', $record->failure_code);
            $this->assertSame($i < 2 ? 'MISMATCH' : 'UNVERIFIED', $record->verification);
        }
    }

    public function test_pending_configuration_fails_closed_without_outbound_chat_for_all_roles(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (config('agents.profiles') as $agent) {
            $role = $agent['slug'];
            $this->configure($role);
            Http::swap(new Factory);
            Http::fake(['*' => Http::response([], 503)]);
            $this->saveRole($role, $role, '');
            Http::swap(new Factory);
            Http::fake();
            try {
                $this->dispatch($role);
                $this->fail('Pending configuration accepted');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('CONFIG_PENDING', $exception->getMessage());
            }
            $this->assertFalse($role === 'scrum-master' ? (new HermesClient)->isAvailable() : (new AgentProfileClient)->isAvailable($role));
            Http::assertNothingSent();
            $this->assertDatabaseHas('agent_dispatches', ['role' => $role, 'failure_code' => 'CONFIG_PENDING', 'verification' => 'UNVERIFIED']);
        }
    }

    public function test_legacy_no_settings_or_missing_schema_keeps_consultation_without_false_metadata_proof(): void
    {
        $this->configure('scrum-master');
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'Legacy consultation']]]])]);
        $this->assertSame('Legacy consultation', $this->dispatch('scrum-master'));
        $this->assertDatabaseHas('agent_dispatches', ['role' => 'scrum-master', 'config_version' => null, 'verification' => 'UNVERIFIED', 'served_model' => null]);
        Schema::drop('agent_dispatches');
        Schema::drop('agent_role_settings');
        Schema::drop('agent_config_versions');
        $this->assertSame('Legacy consultation', $this->dispatch('scrum-master'));
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['model'] === 'scrum-master' && ! isset($r['provider']) && $r->header('X-Hermes-Session-Id')[0] === 'conversation');
    }

    public function test_usage_runtime_metadata_is_supported_but_http_empty_and_connection_failures_are_safe(): void
    {
        $this->actingAs(User::factory()->create());
        $role = 'scrum-master';
        $this->configure($role);
        $this->fakeRuntime('model-0');
        $this->saveRole($role, 'model-0');
        Http::swap(new Factory);
        $this->fakeSessionChat(Http::response(['usage' => ['runtime' => ['provider' => 'test-provider', 'model' => 'model-0']], 'message' => ['content' => 'Usage verified']]));
        $this->assertSame('Usage verified', $this->dispatch($role));
        $this->assertDatabaseHas('agent_dispatches', ['verification' => 'VERIFIED', 'served_model' => 'model-0']);
        foreach ([Http::response(['error' => 'private-test-key'], 401), Http::response(['runtime' => ['provider' => 'test-provider', 'model' => 'model-0'], 'choices' => []]), Http::failedConnection()] as $i => $response) {
            Http::swap(new Factory);
            $this->fakeSessionChat($response);
            try {
                $this->dispatch($role);
                $this->fail('Invalid response accepted');
            } catch (RuntimeException $exception) {
                $this->assertStringNotContainsString('private-test-key', $exception->getMessage());
            }
            $codes = ['RUNTIME_HTTP_ERROR', 'RUNTIME_EMPTY_RESPONSE', 'RUNTIME_CONNECTION_FAILED'];
            $this->assertSame($codes[$i], DB::table('agent_dispatches')->orderByDesc('id')->value('failure_code'));
        }
    }

    public function test_saved_specialist_model_does_not_depend_on_legacy_model_alias(): void
    {
        $this->actingAs(User::factory()->create());
        $role = 'backend-developer';
        $this->configure($role);
        config(['services.agent_profiles.backend-developer.model' => null]);
        $this->fakeRuntime('model-1');
        $this->saveRole($role, 'model-1');
        $this->assertSame('Consultation result', $this->dispatch($role));
    }

    public function test_dispatch_audit_is_immutable_and_contains_full_config_and_skill_hashes(): void
    {
        $this->actingAs(User::factory()->create());
        $this->configure('scrum-master');
        $this->fakeRuntime('model-0');
        $this->saveRole('scrum-master', 'model-0');
        $this->dispatch('scrum-master');
        $record = DB::table('agent_dispatches')->first();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $record->configuration_hash ?? '');
        $skills = json_decode($record->skills, true);
        $body = json_decode(file_get_contents(resource_path('agents/role-packs.json')), true)['profiles']['scrum-master']['skill_body'];
        $this->assertSame(hash('sha256', $body), $skills['scrumspace-scrum-master']);
        foreach (['update', 'delete'] as $operation) {
            try {
                $query = DB::table('agent_dispatches')->where('id', $record->id);
                $operation === 'update' ? $query->update(['served_model' => 'tampered']) : $query->delete();
                $this->fail('Dispatch audit accepted '.$operation);
            } catch (QueryException) {
                $this->assertDatabaseHas('agent_dispatches', ['id' => $record->id, 'served_model' => 'model-0']);
            }
        }
    }

    private function saveRole(string $role, string $model, string $provider = 'test-provider'): void
    {
        $pack = json_decode(file_get_contents(resource_path('agents/role-packs.json')), true)['profiles'][$role];
        $this->putJson('/agents/'.$role.'/config', ['config_version' => 0, 'provider' => $provider, 'model' => $model, 'instructions' => 'Instructions', 'soul' => 'SOUL', 'skills' => $pack['skills']])->assertOk();
    }

    private function configure(string $role): void
    {
        $prefix = $role === 'scrum-master' ? 'services.hermes' : 'services.agent_profiles.'.$role;
        config([$prefix.'.base_url' => 'http://'.$role.'.test/v1', $prefix.'.api_key' => 'private-test-key', $prefix.'.model' => $role]);
    }

    private function fakeRuntime(string $model, ?array $runtime = null): void
    {
        Http::fake([
            '*/api/model/options' => Http::response(['providers' => [['slug' => 'test-provider', 'authenticated' => true, 'models' => ['model-0', 'model-1', 'model-2', 'model-3']]]]),
            '*/api/sessions/*/chat' => fn ($request) => Http::response(['message' => ['role' => 'assistant', 'content' => 'Consultation result'], 'runtime' => $runtime ?? ['provider' => 'test-provider', 'model' => $request['model']]]),
            '*/api/sessions/*' => fn ($request) => Http::response(['session' => ['id' => basename(parse_url($request->url(), PHP_URL_PATH))]]),
        ]);
    }

    private function fakeSessionChat(mixed $response): void
    {
        Http::fake([
            '*/api/sessions/*/chat' => $response,
            '*/api/sessions/*' => fn ($request) => Http::response(['session' => ['id' => basename(parse_url($request->url(), PHP_URL_PATH))]]),
        ]);
    }

    private function dispatch(string $role): string
    {
        return $role === 'scrum-master' ? (new HermesClient)->sendMessage('User private input', 'conversation')
            : (new AgentProfileClient)->sendMessage($role, 'User private input', 'conversation');
    }
}
