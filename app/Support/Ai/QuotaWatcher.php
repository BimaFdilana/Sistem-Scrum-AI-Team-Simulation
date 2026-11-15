<?php

namespace App\Support\Ai;

use App\Services\CliProxyApiClient;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Pengawas kuota: saat kuota habis, cari tahu KAPAN pulih dan kapan sudah pulih.
 *
 * Model Google: baca kuota akun lewat Management API (tanpa biaya model). Waktu pulih
 * dianggap pasti hanya bila jendela yang habis punya waktu reset tetap.
 * Model lain (akun API key): kirim satu pesan kecil berkala; waktu pulih tidak diketahui.
 */
class QuotaWatcher
{
    private const UNKNOWN_INTERVAL_MIN = 10;

    private const KNOWN_INTERVAL_MIN = 15;

    private const COOLDOWN_SETTLE_MIN = 10;

    public function __construct(
        private readonly AntigravityQuota $quota,
        private readonly CliProxyApiClient $gateway,
    ) {}

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

        $model = (string) $state['model'];
        $group = AntigravityQuota::groupForModel($model);

        try {
            // Model Google: kuota mingguan yang benar-benar habis menentukan waktu pulih.
            $google = $group !== null ? $this->checkGoogle($group) : null;
            if ($google === 'waiting') {
                return 'waiting';
            }

            // Jeda gateway (429 berulang): kuota mingguan bisa masih ada padahal penyedia
            // menolak sementara. Tanpa ini peringatan hilang terlalu cepat.
            $cooldown = rescue(fn () => $this->gateway->modelCooldown($model), null, false);
            if ($cooldown !== null) {
                $retry = Carbon::parse($cooldown['retry_at'])->addSeconds(30);
                AiQuota::annotate([
                    'kind' => 'cooldown',
                    'reset_at' => $cooldown['retry_at'],
                    'reset_certain' => false,
                    'cooldown_clear_since' => null,
                    'checked_at' => now()->toIso8601String(),
                    'next_check_at' => ($retry->lessThan(now()->addMinute()) ? now()->addMinute() : $retry)->toIso8601String(),
                ]);

                return 'waiting';
            }
            // Jeda sudah lewat: belum tentu pulih (bisa ditolak lagi). Anggap pulih setelah
            // 10 menit tanpa jeda; panggilan model yang berhasil menghapus peringatan lebih cepat.
            if (($state['kind'] ?? null) === 'cooldown') {
                $since = $state['cooldown_clear_since'] ?? null;
                if ($since !== null && Carbon::parse($since)->lessThanOrEqualTo(now()->subMinutes(self::COOLDOWN_SETTLE_MIN))) {
                    AiQuota::recovered();

                    return 'recovered';
                }
                AiQuota::annotate([
                    'reset_at' => null,
                    'cooldown_clear_since' => $since ?? now()->toIso8601String(),
                    'checked_at' => now()->toIso8601String(),
                    'next_check_at' => now()->addMinutes(5)->toIso8601String(),
                ]);

                return 'waiting';
            }

            if ($google === 'available') {
                AiQuota::recovered();

                return 'recovered';
            }
            if ($google === 'unknown' || ! $allowChatProbe) {
                $this->schedule(self::UNKNOWN_INTERVAL_MIN);

                return 'waiting';
            }

            // Model non-Google: satu pesan kecil; keberhasilan memanggil AiQuota::recovered() sendiri.
            $this->gateway->chatResilient($model, 'Balas dengan satu kata: OK.', 'ping', 20);
            if (AiQuota::current() === null) {
                return 'recovered';
            }
            $this->schedule(self::UNKNOWN_INTERVAL_MIN);

            return 'waiting';
        } catch (Throwable) {
            $this->schedule(self::UNKNOWN_INTERVAL_MIN);

            return 'waiting';
        }
    }

    private function schedule(int $minutes): void
    {
        AiQuota::annotate(['checked_at' => now()->toIso8601String(), 'next_check_at' => now()->addMinutes($minutes)->toIso8601String()]);
    }

    private function checkGoogle(string $group): string
    {
        $accounts = array_values(array_filter(
            $this->quota->snapshot(true)['accounts'],
            fn ($a) => ! $a['disabled'] && ($a['windows'] ?? []) !== []
        ));

        // Per akun: sisa terendah di grup ini menentukan bisa dipakai atau tidak.
        $best = null;
        foreach ($accounts as $account) {
            $windows = array_values(array_filter($account['windows'], fn ($w) => $w['group'] === $group));
            if ($windows === []) {
                continue;
            }
            if (min(array_column($windows, 'remaining')) > 0.001) {
                return 'available';
            }
            // Habis: pulih saat SEMUA jendela yang habis sudah reset (yang terlama menentukan).
            $blocking = array_filter($windows, fn ($w) => $w['remaining'] <= 0.001 && $w['reset_at']);
            $recoverAt = $blocking === [] ? null : max(array_map(fn ($w) => Carbon::parse($w['reset_at'])->timestamp, $blocking));
            $certain = $blocking !== [] && ! in_array(false, array_column($blocking, 'reset_fixed'), true);
            if ($recoverAt !== null && ($best === null || $recoverAt < $best['at'])) {
                $best = ['at' => $recoverAt, 'certain' => $certain];
            }
        }

        if ($accounts === []) {
            return 'unknown'; // tidak ada data kuota (akun nonaktif / API tidak tersedia)
        }
        $patch = ['checked_at' => now()->toIso8601String(), 'kind' => 'quota'];
        if ($best !== null) {
            $resetAt = Carbon::createFromTimestamp($best['at'])->utc();
            $next = now()->addMinutes(self::KNOWN_INTERVAL_MIN);
            // Cek lagi tiap 15 menit, atau tepat setelah waktu pulih bila lebih dekat.
            $recheck = $resetAt->copy()->addMinute();
            $patch += [
                'reset_at' => $resetAt->toIso8601String(),
                'reset_certain' => $best['certain'],
                'next_check_at' => ($recheck->lessThan($next) ? $recheck : $next)->toIso8601String(),
            ];
        } else {
            $patch += ['reset_at' => null, 'reset_certain' => false, 'next_check_at' => now()->addMinutes(self::UNKNOWN_INTERVAL_MIN)->toIso8601String()];
        }
        AiQuota::annotate($patch);

        return 'waiting';
    }
}
