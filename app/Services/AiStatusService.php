<?php

namespace App\Services;

/**
 * Status koneksi AI per agen untuk halaman Tim Agen.
 *
 * Agen terhubung bila akun Google untuk Antigravity sudah login DAN peran itu
 * punya model (pengganti peran atau model utama) yang tersedia di akun.
 */
class AiStatusService
{
    public function __construct(protected AntigravityClient $client) {}

    /**
     * @return array<string, array{connected: bool, model: ?string, provider: ?string, reason: ?string}>
     */
    public function agents(): array
    {
        $result = [];
        $resolver = app(StrictModelResolver::class);
        $loggedIn = $this->client->isEnabled() && $this->client->modelIds() !== [];

        foreach (config('agents.profiles', []) as $profile) {
            $slug = $profile['slug'];
            if (! $loggedIn) {
                $result[$slug] = [
                    'connected' => false,
                    'model' => null,
                    'provider' => null,
                    'reason' => 'Akun Google untuk AI belum terhubung di halaman Model AI.',
                ];

                continue;
            }

            $check = $resolver->check($slug);
            $result[$slug] = [
                'connected' => $check['model'] !== null,
                'model' => $check['model'],
                'provider' => $check['model'] !== null ? AiUsageRecorder::providerForModel($check['model']) : null,
                'reason' => $check['model'] !== null ? null : $check['short_reason'],
            ];
        }

        return $result;
    }
}
