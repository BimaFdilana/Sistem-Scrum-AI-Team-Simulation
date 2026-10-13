<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Client for the local CLIProxyAPI gateway, covering both the
 * OpenAI-compatible inference API (/v1/...) and the Management API
 * (/v0/management/...) used for OAuth account linking.
 */
class CliProxyApiClient
{
    protected string $baseUrl;

    protected ?string $managementKey;

    protected ?string $apiKey;

    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('ai.cliproxy.base_url'), '/');
        $this->managementKey = config('ai.cliproxy.management_key') ?: null;
        $this->apiKey = config('ai.cliproxy.api_key') ?: null;
        $this->timeout = (int) config('ai.cliproxy.timeout', 15);
    }

    public function isAvailable(): bool
    {
        try {
            $response = Http::timeout(3)->get($this->baseUrl.'/v1/models');

            // 401/403 both prove the gateway is up and responding; it just
            // requires an API key for unauthenticated callers.
            return $response->successful() || in_array($response->status(), [401, 403], true);
        } catch (Throwable) {
            return false;
        }
    }

    public function isManagementAvailable(): bool
    {
        if (! $this->managementKey) {
            return false;
        }

        try {
            $response = $this->management()->get('/auth-files');

            return $response->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * List linked OAuth accounts: ['files' => [...]].
     * Each file carries id/email/status/disabled plus provider info.
     */
    public function accounts(): array
    {
        try {
            $response = $this->management()->get('/auth-files');

            return $response->successful() ? (array) $response->json() : ['files' => []];
        } catch (Throwable) {
            return ['files' => []];
        }
    }

    /**
     * Start an OAuth login: returns ['state' => ..., 'url' => ...].
     */
    public function authUrl(string $provider): ?array
    {
        try {
            $response = $this->management()->get("/{$provider}-auth-url");

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();

            return isset($data['url'], $data['state'])
                ? ['url' => $data['url'], 'state' => $data['state']]
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Poll the login status for a given state token.
     */
    public function authStatus(string $state): array
    {
        try {
            $response = $this->management()->get('/get-auth-status', ['state' => $state]);

            return (array) $response->json();
        } catch (Throwable) {
            return ['status' => 'error'];
        }
    }

    /**
     * Complete an OAuth login by submitting the authorization code
     * (extracted from the localhost callback URL the user pastes).
     */
    public function submitCallback(string $state, string $code): bool
    {
        try {
            $response = $this->management()->post('/oauth-callback', [
                'state' => $state,
                'code' => $code,
            ]);

            return $response->successful()
                && ($response->json('status') === 'ok');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Disable (disconnect) a linked account by its auth-file name.
     */
    public function disableAccount(string $name): bool
    {
        try {
            $response = $this->management()->patch('/auth-files/status', [
                'name' => $name,
                'disabled' => true,
            ]);

            return $response->successful();
        } catch (Throwable) {
            return false;
        }
    }

    public function enableAccount(string $name): bool
    {
        try {
            $response = $this->management()->patch('/auth-files/status', [
                'name' => $name,
                'disabled' => false,
            ]);

            return $response->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Extract the OAuth `code` (or `error`) from a pasted callback URL.
     */
    public static function extractCode(string $callbackUrl): ?string
    {
        $parts = parse_url(trim($callbackUrl));

        if (! isset($parts['query'])) {
            return null;
        }

        parse_str($parts['query'], $query);

        return $query['code'] ?? null;
    }

    /**
     * Apakah jalur agen via CLIProxyAPI aktif. Mati saat testing agar
     * panggilan agen tidak bocor ke gateway asli.
     */
    public function agentsEnabled(): bool
    {
        return (bool) config('ai.cliproxy.agents_enabled', true);
    }

    /**
     * Model IDs available on the OpenAI-compatible inference API.
     * Cached briefly; empty when gateway unreachable or no account linked.
     *
     * @return array<int, array{id: string, owned_by: string}>
     */
    public function models(): array
    {
        if (! $this->apiKey) {
            return [];
        }

        return \Illuminate\Support\Facades\Cache::remember('cliproxy:models', 60, function (): array {
            try {
                $response = Http::baseUrl($this->baseUrl)->withToken($this->apiKey)
                    ->timeout($this->timeout)->acceptJson()->get('/v1/models');
                if (! $response->successful()) {
                    return [];
                }
                $data = $response->json('data');
                if (! is_array($data)) {
                    return [];
                }

                return array_values(array_filter(array_map(
                    fn ($m) => isset($m['id']) ? ['id' => (string) $m['id'], 'owned_by' => (string) ($m['owned_by'] ?? '')] : null,
                    $data
                )));
            } catch (Throwable) {
                return [];
            }
        });
    }

    /**
     * Pick the best CLIProxyAPI model for an agent role, honoring the role's
     * preferred_provider from config/agents.php (disetujui Bima 2026-10-13):
     * QA=anthropic, BE=codex, FE=google, SM=model terpintar yang tersedia.
     * Returns null when nothing suitable is available.
     */
    public function modelForRole(string $role): ?string
    {
        if (! $this->agentsEnabled()) {
            return null;
        }
        $models = $this->models();
        if ($models === []) {
            return null;
        }
        $profile = collect(config('agents.profiles'))->firstWhere('slug', $role);
        $preferred = strtolower((string) ($profile['preferred_provider'] ?? 'any'));

        $byProvider = fn (string $provider) => array_values(array_filter(
            $models,
            fn ($m) => self::modelMatchesProvider($m['id'], $m['owned_by'], $provider)
        ));

        $candidates = $byProvider($preferred);
        if ($candidates === [] && $preferred !== 'any') {
            // Fallback: provider mana pun yang tersedia.
            $candidates = $models;
        }
        if ($candidates === []) {
            return null;
        }

        return self::pickBest($candidates);
    }

    /** Model terpintar yang tersedia (untuk Scrum Master). */
    public function smartestModel(): ?string
    {
        if (! $this->agentsEnabled()) {
            return null;
        }
        $models = $this->models();

        return $models === [] ? null : self::pickBest($models);
    }

    /**
     * Model IDs dikelompokkan per provider OAuth: anthropic/codex/google.
     *
     * @return array<string, list<string>>
     */
    public function modelsByProvider(): array
    {
        if (! $this->agentsEnabled()) {
            return [];
        }
        $grouped = ['anthropic' => [], 'codex' => [], 'google' => []];
        foreach ($this->models() as $m) {
            foreach (array_keys($grouped) as $provider) {
                if (self::modelMatchesProvider($m['id'], $m['owned_by'], $provider)) {
                    $grouped[$provider][] = $m['id'];
                    break;
                }
            }
        }

        return array_filter($grouped);
    }

    /**
     * Model final untuk sebuah peran: hormati pilihan tersimpan (snapshot
     * terverifikasi dari halaman Konfigurasi Agent) bila modelnya masih
     * tersedia saat ini; selain itu pakai pemetaan otomatis modelForRole().
     */
    public function resolveModel(string $role): ?string
    {
        if (! $this->agentsEnabled()) {
            return null;
        }
        $models = $this->models();
        if ($models === []) {
            return null;
        }
        $snapshot = app(AgentConfiguration::class)->current($role);
        $saved = is_array($snapshot) ? ($snapshot['model'] ?? null) : null;
        if (($snapshot['status'] ?? null) === 'verified' && is_string($saved)
            && in_array($saved, array_column($models, 'id'), true)) {
            return $saved;
        }

        return $this->modelForRole($role);
    }

    /**
     * OpenAI-compatible chat completion via CLIProxyAPI.
     * Returns the assistant text, or null on failure.
     */
    public function chat(string $model, string $systemPrompt, string $userMessage, int $timeout = 120): ?string
    {
        if (! $this->agentsEnabled() || ! $this->apiKey) {
            return null;
        }
        try {
            $response = Http::baseUrl($this->baseUrl)->withToken($this->apiKey)
                ->timeout($timeout)->acceptJson()->post('/v1/chat/completions', [
                    'model' => $model,
                    'stream' => false,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                ]);
            if (! $response->successful()) {
                return null;
            }
            $content = $response->json('choices.0.message.content');

            return is_string($content) && trim($content) !== '' ? trim($content) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function modelMatchesProvider(string $id, string $ownedBy, string $provider): bool
    {
        $id = strtolower($id);
        $ownedBy = strtolower($ownedBy);

        return match ($provider) {
            'anthropic' => str_contains($id, 'claude') || str_contains($ownedBy, 'anthropic'),
            'codex' => str_contains($id, 'gpt') || str_contains($id, 'codex') || str_contains($ownedBy, 'openai'),
            'google', 'antigravity', 'gemini' => str_contains($id, 'gemini') || str_contains($ownedBy, 'google'),
            default => true,
        };
    }

    /** Heuristik "terbaik": hindari model image, utamakan flagship. */
    private static function pickBest(array $models): string
    {
        $ids = array_column($models, 'id');
        $nonImage = array_values(array_filter($ids, fn ($id) => ! str_contains(strtolower($id), 'image')));
        $pool = $nonImage !== [] ? $nonImage : $ids;
        $ranked = [
            'claude-opus', 'claude-sonnet', 'claude',
            'gpt-5', 'gpt-6', 'gpt-4', 'gpt',
            'gemini-2.5-pro', 'gemini-3', 'gemini-2.5', 'gemini-3.1-pro', 'gemini',
        ];
        foreach ($ranked as $needle) {
            foreach ($pool as $id) {
                if (str_contains(strtolower($id), $needle)) {
                    return $id;
                }
            }
        }

        return $pool[0];
    }

    protected function management()
    {
        return Http::baseUrl($this->baseUrl.'/v0/management')
            ->withToken($this->managementKey)
            ->timeout($this->timeout)
            ->acceptJson();
    }
}
