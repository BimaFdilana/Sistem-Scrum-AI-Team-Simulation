<?php

namespace App\Support\Ai;

use App\Services\CliProxyApiClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Kuota akun Google (Antigravity) yang dibaca lewat Management API gateway (`api-call`),
 * cara yang sama dengan Management Center resmi: sisa kuota dan waktu reset per grup
 * model dan per jendela (mingguan; 5 jam bila tingkat akun menyediakannya), plus
 * tingkat langganan akun.
 *
 * Penting: untuk jendela yang masih penuh (sisa 100%), "waktu reset" dari Google hanya
 * sekarang + panjang jendela dan bergeser terus. Karena itu waktu reset baru dianggap
 * pasti (`reset_fixed`) setelah kuota terpakai (sisa di bawah 100%).
 * Endpoint Google ini internal dan tidak terdokumentasi; semua pembacaan tahan gagal.
 */
class AntigravityQuota
{
    public const SUMMARY_URL = 'https://cloudcode-pa.googleapis.com/v1internal:retrieveUserQuotaSummary';

    public const TIER_URL = 'https://daily-cloudcode-pa.googleapis.com/v1internal:loadCodeAssist';

    /** Identitas klien resmi; Google memilih grup/jendela yang dikembalikan menurut versi ini. */
    public const USER_AGENT = 'antigravity/cli/1.5.5 (aidev_client; os_type=darwin; arch=arm64)';

    public const TIER_USER_AGENT = 'antigravity/hub/2.23.0 darwin/arm64';

    private const CACHE_KEY = 'ai-quota:antigravity-snapshot';

    private const TTL_SECONDS = 900;

    public function __construct(private readonly CliProxyApiClient $gateway) {}

    /** Grup kuota Google untuk sebuah model: 'gemini', '3p' (Claude/GPT), atau null (bukan model Google). */
    public static function groupForModel(string $model): ?string
    {
        $model = AiQuota::normalize($model);

        return match (true) {
            str_starts_with($model, 'gemini-') => 'gemini',
            str_starts_with($model, 'claude-'), str_starts_with($model, 'gpt-') => '3p',
            default => null,
        };
    }

    /**
     * @return array{fetched_at: string, accounts: array<int, array<string, mixed>>}
     */
    public function snapshot(bool $fresh = false, int $timeout = 20): array
    {
        try {
            $cached = Cache::get(self::CACHE_KEY);
            if (! $fresh && is_array($cached) && (time() - Carbon::parse($cached['fetched_at'])->timestamp) < self::TTL_SECONDS) {
                return $cached;
            }
        } catch (Throwable) {
            $cached = null;
        }

        $accounts = [];
        $files = $this->gateway->accounts($fresh)['files'] ?? [];
        foreach ((array) $files as $file) {
            if (! is_array($file) || strtolower((string) ($file['provider'] ?? $file['type'] ?? '')) !== 'antigravity') {
                continue;
            }
            $disabled = (bool) ($file['disabled'] ?? false) || ($file['status'] ?? '') === 'disabled';
            $entry = [
                'label' => (string) ($file['label'] ?? $file['email'] ?? $file['name'] ?? 'akun Google'),
                'disabled' => $disabled,
                'plan' => null,
                'tier' => null,
                'windows' => [],
                'error' => null,
            ];
            $authIndex = (string) ($file['auth_index'] ?? '');
            $projectId = (string) ($file['project_id'] ?? '');
            if (! $disabled && $authIndex !== '') {
                $result = $this->gateway->apiCall($authIndex, self::SUMMARY_URL, $projectId !== '' ? ['project' => $projectId] : [], self::USER_AGENT, $timeout);
                if ($result !== null && $result['status'] >= 200 && $result['status'] < 300 && is_array($result['body'])) {
                    $entry['windows'] = self::parseSummary($result['body']);
                } else {
                    $entry['error'] = 'Kuota tidak dapat dibaca dari Google saat ini.';
                    // Pertahankan data terakhir yang berhasil agar timer tidak hilang karena gangguan sesaat.
                    $entry['windows'] = $this->previousWindows($cached, $authIndex);
                }
                [$entry['plan'], $entry['tier']] = $this->tier($authIndex, $timeout);
            }
            $entry['auth_index'] = $authIndex;
            $accounts[] = $entry;
        }

        $snapshot = ['fetched_at' => now()->toIso8601String(), 'accounts' => $accounts];
        try {
            Cache::put(self::CACHE_KEY, $snapshot, now()->addHours(2));
        } catch (Throwable) {
            // cache bersifat bantuan
        }

        return $snapshot;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function parseSummary(array $body): array
    {
        $windows = [];
        foreach ((array) ($body['groups'] ?? []) as $group) {
            if (! is_array($group)) {
                continue;
            }
            foreach ((array) ($group['buckets'] ?? []) as $bucket) {
                if (! is_array($bucket)) {
                    continue;
                }
                $fraction = $bucket['remainingFraction'] ?? $bucket['remaining_fraction'] ?? null;
                if (! is_numeric($fraction)) {
                    continue;
                }
                $remaining = max(0.0, min(1.0, (float) $fraction));
                $resetRaw = $bucket['resetTime'] ?? $bucket['reset_time'] ?? null;
                $resetAt = null;
                if (is_string($resetRaw) && $resetRaw !== '') {
                    try {
                        $resetAt = Carbon::parse($resetRaw)->utc()->toIso8601String();
                    } catch (Throwable) {
                        $resetAt = null;
                    }
                }
                $id = strtolower((string) ($bucket['bucketId'] ?? $bucket['bucket_id'] ?? ''));
                $name = strtolower((string) ($group['displayName'] ?? $group['groupId'] ?? ''));
                $window = strtolower(trim((string) ($bucket['window'] ?? '')));
                $windows[] = [
                    'group' => str_contains($id, 'gemini') || str_contains($name, 'gemini') ? 'gemini' : '3p',
                    'group_label' => (string) ($group['displayName'] ?? ($id !== '' ? $id : 'Kuota')),
                    'window' => $window,
                    'window_label' => self::windowLabel($window),
                    'remaining' => $remaining,
                    'reset_at' => $resetAt,
                    // Jendela penuh: reset hanyalah "sekarang + panjang jendela", bukan jadwal tetap.
                    'reset_fixed' => $resetAt !== null && $remaining < 0.999,
                ];
            }
        }

        return $windows;
    }

    /** @return array{0: ?string, 1: ?string} [kode tingkat, nama tingkat] */
    public static function parseTier(array $body): array
    {
        $paid = (array) ($body['paidTier'] ?? $body['paid_tier'] ?? []);
        $current = (array) ($body['currentTier'] ?? $body['current_tier'] ?? []);
        $tier = ($paid['id'] ?? null) ? $paid : $current;
        $id = isset($tier['id']) ? (string) $tier['id'] : null;
        $name = isset($tier['name']) ? (string) $tier['name'] : null;

        return [match ($id) {
            'free-tier' => 'free',
            'g1-pro-tier' => 'pro',
            'g1-ultra-tier' => 'ultra',
            'g1-ultra-lite-tier' => 'ultra-lite',
            null => null,
            default => 'unknown',
        }, $name];
    }

    public static function windowLabel(string $window): string
    {
        return match ($window) {
            '5h', 'five-hour', 'five_hour' => '5 jam',
            'weekly', 'week' => 'Mingguan',
            default => $window !== '' ? $window : 'Kuota',
        };
    }

    public static function planLabel(?string $plan): string
    {
        return match ($plan) {
            'free' => 'Gratis',
            'pro' => 'Pro',
            'ultra' => 'Ultra',
            'ultra-lite' => 'Ultra Lite',
            default => 'Tingkat tidak diketahui',
        };
    }

    /** @return array{0: ?string, 1: ?string} */
    private function tier(string $authIndex, int $timeout): array
    {
        $key = 'ai-quota:tier:'.$authIndex;
        try {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return [$cached[0] ?? null, $cached[1] ?? null];
            }
        } catch (Throwable) {
            // lanjut baca
        }
        $result = $this->gateway->apiCall($authIndex, self::TIER_URL, ['metadata' => ['ideType' => 'ANTIGRAVITY']], self::TIER_USER_AGENT, $timeout);
        if ($result === null || ! is_array($result['body'])) {
            return [null, null];
        }
        $tier = self::parseTier($result['body']);
        try {
            Cache::put($key, $tier, now()->addHours(6));
        } catch (Throwable) {
            // abaikan
        }

        return $tier;
    }

    /** @return array<int, array<string, mixed>> */
    private function previousWindows(mixed $cached, string $authIndex): array
    {
        foreach ((array) ($cached['accounts'] ?? []) as $account) {
            if (($account['auth_index'] ?? null) === $authIndex) {
                return (array) ($account['windows'] ?? []);
            }
        }

        return [];
    }
}
