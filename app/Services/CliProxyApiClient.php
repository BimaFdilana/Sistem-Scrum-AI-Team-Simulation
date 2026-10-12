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

    protected function management()
    {
        return Http::baseUrl($this->baseUrl.'/v0/management')
            ->withToken($this->managementKey)
            ->timeout($this->timeout)
            ->acceptJson();
    }
}
