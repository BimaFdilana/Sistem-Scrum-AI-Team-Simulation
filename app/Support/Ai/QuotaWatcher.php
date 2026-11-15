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
            if ($group !== null) {
                return $this->checkGoogle($group);
            }
            if (! $allowChatProbe) {
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
                AiQuota::recovered();

                return 'recovered';
            }
            // Habis: pulih saat SEMUA jendela yang habis sudah reset (yang terlama menentukan).
            $blocking = array_filter($windows, fn ($w) => $w['remaining'] <= 0.001 && $w['reset_at']);
            $recoverAt = $blocking === [] ? null : max(array_map(fn ($w) => Carbon::parse($w['reset_at'])->timestamp, $blocking));
            $certain = $blocking !== [] && ! in_array(false, array_column($blocking, 'reset_fixed'), true);
            if ($recoverAt !== null && ($best === null || $recoverAt < $best['at'])) {
                $best = ['at' => $recoverAt, 'certain' => $certain];
            }
        }

        $patch = ['checked_at' => now()->toIso8601String()];
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
