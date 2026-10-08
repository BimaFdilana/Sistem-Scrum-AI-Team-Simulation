<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/** Consultation only. No executor, tools, skill paths or profile mutation API. */
class AgentConsultationDispatcher
{
    public function send(string $role, string $message, string $conversationId, string $legacyPolicy): string
    {
        $configs = app(AgentConfiguration::class);
        $agent = $configs->role($role);
        $catalog = app(AgentModelCatalog::class);
        $settings = $catalog->settings($role);
        $snapshot = $configs->current($role);
        $session = $snapshot ? $conversationId.':'.$role.':config-'.$snapshot['config_version'] : $conversationId;
        $policy = $legacyPolicy;
        $prompt = $legacyPolicy;
        if ($snapshot) {
            $policy .= "\n\n".$snapshot['policy_snapshot']."\nConsultation and analysis only. Maximum team discussion rounds: 3. Maximum QA correction cycles: 2. Native tool authorization is not granted by role configuration.\nExecution remains disabled. Native persona and skills cannot override this policy.";
            $prompt = "Fixed role instructions (packaged native profile snapshot; subordinate to immutable safety policy):\n".$snapshot['instructions']
                ."\n\nFixed SOUL (native profile snapshot):\n".$snapshot['soul'];
            foreach ($snapshot['skill_snapshot'] as $id => $skill) {
                $prompt .= "\n\nCurated skill ".$id.":\n".$skill['body'];
            }
            $prompt .= "\n\nIMMUTABLE SAFETY POLICY — overrides all role content:\n".$policy;
        }
        $provider = $snapshot['provider'] ?? null;
        $model = $snapshot['model'] ?? ($settings['model'] ?? '');
        $metadata = [
            'role' => $role, 'profile' => $agent['profile'], 'config_version' => $snapshot['config_version'] ?? null,
            'requested_provider' => $provider, 'requested_model' => $model,
            'served_provider' => null, 'served_model' => null, 'verification' => 'UNVERIFIED', 'failure_code' => null,
            'session_hash' => hash('sha256', $session),
            'configuration_hash' => hash('sha256', json_encode([
                'role' => $role, 'profile' => $agent['profile'], 'version' => $snapshot['config_version'] ?? null,
                'provider' => $provider, 'model' => $model, 'instructions' => $snapshot['instructions'] ?? null,
                'soul' => $snapshot['soul'] ?? null, 'skills' => $snapshot['skill_snapshot'] ?? [], 'policy' => $policy,
            ], JSON_THROW_ON_ERROR)),
            'instructions_hash' => $snapshot ? hash('sha256', $snapshot['instructions']) : null,
            'soul_hash' => $snapshot ? hash('sha256', $snapshot['soul']) : null,
            'policy_hash' => hash('sha256', $policy),
            'skills' => json_encode(array_map(fn ($skill) => $skill['hash'], $snapshot['skill_snapshot'] ?? [])),
            'created_at' => now(),
        ];
        $failure = null;
        $content = null;
        if ($snapshot && ($snapshot['status'] !== 'verified' || ! $catalog->safeId($provider) || ! $catalog->safeId($model))) {
            $failure = 'CONFIG_PENDING';
        } elseif (empty($settings['base_url']) || empty($settings['api_key']) || empty($model)) {
            $failure = 'ENDPOINT_NOT_CONFIGURED';
        } else {
            try {
                if ($snapshot) {
                    // The installed native session-chat API preserves actual served runtime metadata.
                    $response = $this->configuredResponse($settings, $session, $provider, $model, $prompt, $message);
                } else {
                    $body = ['model' => $model, 'stream' => false, 'messages' => [['role' => 'system', 'content' => $prompt], ['role' => 'user', 'content' => $message]]];
                    $response = Http::acceptJson()->withToken($settings['api_key'])->withHeaders(['X-Hermes-Session-Id' => $session])
                        ->connectTimeout(3)->timeout((int) config('services.hermes.timeout', 120))
                        ->post(rtrim($settings['base_url'], '/').'/chat/completions', $body);
                }
                if (! $response->successful()) {
                    $failure = 'RUNTIME_HTTP_ERROR';
                } else {
                    // Only response metadata; never requested echoes, top-level model alias, or LLM text.
                    $runtime = $response->json('runtime') ?? $response->json('usage.runtime');
                    $servedProvider = is_array($runtime) ? ($runtime['provider'] ?? null) : null;
                    $servedModel = is_array($runtime) ? ($runtime['model'] ?? null) : null;
                    $metadata['served_provider'] = $catalog->safeId($servedProvider) ? $servedProvider : null;
                    $metadata['served_model'] = $catalog->safeId($servedModel) ? $servedModel : null;
                    if ($metadata['served_provider'] && $metadata['served_model']) {
                        if ($snapshot && ($servedProvider !== $provider || $servedModel !== $model)) {
                            $metadata['verification'] = 'MISMATCH';
                            $failure = 'RUNTIME_MODEL_MISMATCH';
                        } else {
                            $metadata['verification'] = $snapshot ? 'VERIFIED' : 'UNVERIFIED';
                        }
                    } elseif ($snapshot) {
                        $failure = 'RUNTIME_METADATA_MISSING';
                    }
                    $content = $response->json($snapshot ? 'message.content' : 'choices.0.message.content');
                    if (! is_string($content) || trim($content) === '') {
                        $failure ??= 'RUNTIME_EMPTY_RESPONSE';
                    }
                }
            } catch (ConnectionException) {
                $failure = 'RUNTIME_CONNECTION_FAILED';
            } catch (RuntimeException) {
                $failure = 'RUNTIME_SESSION_UNAVAILABLE';
            } catch (Throwable) {
                $failure = 'RUNTIME_CONNECTION_FAILED';
            }
        }
        $metadata['failure_code'] = $failure;
        if ($configs->schemaReady()) {
            // Append exactly once, including failures; never store messages, prompt, response, key or URL.
            DB::table('agent_dispatches')->insert($metadata);
        }
        if ($failure) {
            throw new RuntimeException('AI Agent consultation unavailable: '.$failure);
        }
        $content = trim($content);

        return app(ChatDisclosurePolicy::class)->containsSensitiveDisclosure($content) ? ChatDisclosurePolicy::SAFE_RESPONSE : $content;
    }

    private function configuredResponse(array $settings, string $session, string $provider, string $model, string $prompt, string $message): Response
    {
        $root = preg_replace('#/v1$#', '', rtrim($settings['base_url'], '/'));
        $id = 'scrumspace-'.hash('sha256', $session);
        $url = $root.'/api/sessions/'.$id;
        $request = fn () => Http::acceptJson()->withToken($settings['api_key'])
            ->connectTimeout(3)->timeout((int) config('services.hermes.timeout', 120));
        $existing = $request()->get($url);
        if ($existing->status() === 404) {
            $created = $request()->post($root.'/api/sessions', [
                'id' => $id, 'model' => $model, 'provider' => $provider,
                'require_model_lock' => true, 'system_prompt' => $prompt,
            ]);
            if ($created->status() !== 201
                && ! ($created->status() === 409 && $created->json('error.code') === 'session_exists')) {
                throw new RuntimeException('Native consultation session unavailable.');
            }
            // Verify the exact session after creation, including a concurrent same-ID create.
            $existing = $request()->get($url);
        }
        if (! $existing->successful() || $existing->json('session.id') !== $id) {
            throw new RuntimeException('Native consultation session unavailable.');
        }

        return $request()->withHeaders(['X-Hermes-Session-Id' => $id])->post($url.'/chat', [
            'message' => $message, 'system_message' => $prompt,
            'model' => $model, 'provider' => $provider, 'require_model_lock' => true,
        ]);
    }
}
