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
     * Aturan tanpa-default: agent terhubung ⟺ role itu punya model yang
     * dipilih PO di halaman Konfigurasi Agent DAN modelnya tersedia di
     * gateway. Display tidak lagi mengklaim model pemetaan otomatis
     * seolah-olah dipakai — bila tidak ada pilihan, tampilkan
     * "belum dipilih" dengan alasan jujur dari resolver.
     */
    public function agents(): array
    {
        $result = [];
        $resolver = app(StrictModelResolver::class);

        foreach (config('agents.profiles', []) as $profile) {
            $slug = $profile['slug'];
            if (! $this->client->agentsEnabled()) {
                $result[$slug] = [
                    'connected' => false,
                    'model' => null,
                    'provider' => null,
                    'reason' => 'Jalur agent AI dimatikan di konfigurasi.',
                ];
                continue;
            }
            $check = $resolver->check($slug);
            if ($check['model'] !== null) {
                $result[$slug] = [
                    'connected' => true,
                    'model' => $check['model'],
                    'provider' => $this->providerLabelForModel($check['model']),
                    'reason' => null,
                ];
                continue;
            }

            $result[$slug] = [
                'connected' => false,
                'model' => null,
                'provider' => null,
                'reason' => $check['short_reason'],
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
}
