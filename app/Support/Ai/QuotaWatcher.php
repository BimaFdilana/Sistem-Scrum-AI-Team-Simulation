<?php

namespace App\Support\Ai;

use App\Services\AntigravityClient;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Pengawas kuota: saat kuota AI habis tercatat, kirim satu pesan kecil lewat
 * Antigravity secara berkala. Pesan yang berhasil menghapus peringatan
 * (AiQuota::recovered dipanggil oleh AntigravityClient). Antigravity tidak
 * menyediakan waktu reset kuota di mode headless, jadi waktu pulih tidak
 * diketahui pasti.
 */
class QuotaWatcher
{
    private const INTERVAL_MIN = 15;

    public function __construct(private readonly AntigravityClient $client) {}

    /**
     * @return string idle|skipped|recovered|waiting
     */
    public function check(bool $force = false, bool $allowChatProbe = true): string
    {
        $state = AiQuota::current();
        if ($state === null) {
            return 'idle';
        }
        if (! $force && ! empty($state['next_check_at']) && Carbon::parse($state['next_check_at'])->isFuture()) {
            return 'skipped';
        }
        if (! $allowChatProbe) {
            $this->schedule();

            return 'waiting';
        }

        try {
            $this->client->chatResilient((string) $state['model'], 'Balas dengan satu kata: OK.', 'ping', 60);
        } catch (Throwable) {
            // Gagal memeriksa = tetap menunggu; dicoba lagi nanti.
        }

        if (AiQuota::current() === null) {
            return 'recovered';
        }
        $this->schedule();

        return 'waiting';
    }

    private function schedule(): void
    {
        AiQuota::annotate([
            'kind' => 'quota',
            'reset_at' => null,
            'reset_certain' => false,
            'checked_at' => now()->toIso8601String(),
            'next_check_at' => now()->addMinutes(self::INTERVAL_MIN)->toIso8601String(),
        ]);
    }
}
