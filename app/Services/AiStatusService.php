<?php

namespace App\Services;

/**
 * Satu-satunya sumber kebenaran untuk status koneksi AI.
 *
 * Halaman AI Providers dan halaman Agent Team membaca dari sini, sehingga
 * keduanya tidak mungkin bertentangan: status agent diturunkan langsung
 * dari status provider + model yang ter-resolve untuk tiap peran.
 */
class AiStatusService
{
    public function __construct(protected CliProxyApiClient $client) {}

    /**
     * Status per provider: ['slug' => ['label','description','models',
     * 'connected','email','account']].
     */
    public function providers(): array
    {
        $result = [];

        foreach (config('ai.providers', []) as $slug => $meta) {
            $account = $this->client->accountForProvider($slug);
            $connected = $account !== null && ! ($account['disabled'] ?? false);

            $result[$slug] = [
                'slug' => $slug,
                'label' => $meta['label'] ?? $slug,
                'description' => $meta['description'] ?? '',
                'models' => $meta['models'] ?? [],
                'connected' => $connected,
                'email' => $connected ? ($account['email'] ?? null) : null,
                'account' => $account,
            ];
        }

        return $result;
    }

    /**
     * Status per agent: ['slug' => ['connected','model','provider','reason']].
     * Agent terhubung ⟺ provider-nya terhubung DAN ada model untuk perannya.
     */
    public function agents(): array
    {
        $result = [];

        foreach (config('agents.profiles', []) as $profile) {
            $slug = $profile['slug'];
            $model = $slug === 'scrum-master'
                ? $this->client->smartestModel()
                : $this->client->modelForRole($slug);

            if ($model !== null) {
                $result[$slug] = [
                    'connected' => true,
                    'model' => $model,
                    'provider' => $this->providerLabelForModel($model),
                    'reason' => null,
                ];
                continue;
            }

            $result[$slug] = [
                'connected' => false,
                'model' => null,
                'provider' => null,
                'reason' => $this->unavailableReason(),
            ];
        }

        return $result;
    }

    /** Label provider untuk sebuah model ID. */
    public function providerLabelForModel(string $model): ?string
    {
        // modelsByProvider() memakai grup registry (google/codex/anthropic/
        // mimo/...); slug config OAuth untuk Google adalah 'antigravity'.
        $groupToSlug = ['anthropic' => 'anthropic', 'codex' => 'codex', 'google' => 'antigravity'];

        foreach ($this->client->modelsByProvider() as $group => $ids) {
            if (in_array($model, $ids, true)) {
                $slug = $groupToSlug[$group] ?? null;
                if ($slug !== null) {
                    return config("ai.providers.{$slug}.label") ?? $slug;
                }

                return config("ai.model_providers.{$group}.label") ?? $group;
            }
        }

        return null;
    }

    /** Alasan jujur kenapa sebuah agent belum terhubung. */
    protected function unavailableReason(): string
    {
        if (! $this->client->agentsEnabled()) {
            return 'Jalur agent AI dimatikan di konfigurasi.';
        }

        foreach ($this->providers() as $provider) {
            if ($provider['connected']) {
                return 'Provider terhubung, tetapi tidak ada model yang cocok untuk peran ini.';
            }
        }

        return 'Belum ada provider AI yang terhubung.';
    }
}
