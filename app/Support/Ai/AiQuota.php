<?php

namespace App\Support\Ai;

use App\Jobs\CheckAiQuotaJob;
use App\Models\Notification;
use App\Models\User;
use App\Services\ActiveModelService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Status kuota model AI. Saat penyedia menolak karena kuota habis
 * ("Resource has been exhausted", "quota"), semua agen yang memakai model itu
 * berhenti. Dulu kejadian ini diam-diam: eksekusi menggantung tanpa peringatan.
 * Kini dicatat sekali per kejadian, PO diberi tahu (banner, notifikasi, chat),
 * dan catatan dihapus otomatis begitu model itu berhasil dipanggil lagi.
 */
class AiQuota
{
    private const KEY = 'ai-quota:exhausted';

    /** Pesan penyedia yang berarti kuota/limit habis (bukan sekadar sibuk sesaat). */
    public static function isQuotaText(?string $text): bool
    {
        $lower = mb_strtolower((string) $text);

        return str_contains($lower, 'resource has been exhausted')
            || str_contains($lower, 'resource_exhausted')
            || str_contains($lower, 'quota')
            || str_contains($lower, 'insufficient_quota')
            || str_contains($lower, 'usage limit')
            || str_contains($lower, 'credit balance');
    }

    public static function exhausted(?string $model, ?string $detail = null): void
    {
        try {
            // Nama model diambil dari pesan galat yang sebenarnya bila ada ("> build · gemini-x",
            // "model=gemini-x"), karena model di pengaturan bisa sudah diganti saat galat terjadi.
            $model = self::modelFromDetail((string) $detail) ?? $model;
            $model = $model ? self::normalize($model) : 'model aktif';
            $current = Cache::get(self::KEY);
            $isNew = ! is_array($current) || ($current['model'] ?? null) !== $model;
            $state = [
                'model' => $model,
                'since' => $isNew ? now()->toIso8601String() : ($current['since'] ?? now()->toIso8601String()),
                'last_seen' => now()->toIso8601String(),
                'detail' => Str::limit(trim((string) $detail), 300),
            ];
            // Pertahankan hasil pengawas (waktu pulih, jadwal cek) bila kejadian ini lanjutan.
            if (! $isNew && is_array($current)) {
                $state += array_intersect_key($current, array_flip(['reset_at', 'reset_certain', 'checked_at', 'next_check_at']));
            }
            self::store($state);

            if ($isNew) {
                Log::warning('Kuota model AI habis', ['model' => $model, 'detail' => Str::limit((string) $detail, 300)]);
                // Cari tahu kapan kuota pulih (membaca kuota akun Google) tanpa menahan pemanggil.
                rescue(fn () => CheckAiQuotaJob::dispatch(), null, false);
                foreach (User::query()->pluck('id') as $userId) {
                    Notification::query()->create([
                        'user_id' => $userId,
                        'type' => 'ai_quota',
                        'title' => 'Kuota AI habis',
                        'message' => 'Model '.$model.' ditolak penyedia karena kuota habis. Estimasi, eksekusi, dan chat Scrum Master berhenti sampai kuota pulih atau model aktif diganti di AI Providers.',
                        'link' => route('ai-providers.index', [], false),
                    ]);
                }
            }
        } catch (Throwable $exception) {
            Log::warning('Status kuota AI tidak bisa dicatat', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * Ada panggilan AI yang berhasil: agen bekerja lagi, jadi peringatan dihapus.
     * Semua agen memakai satu model aktif, sehingga keberhasilan model mana pun
     * berarti tim tidak lagi tertahan kuota.
     */
    public static function recovered(?string $model = null, bool $notify = true): void
    {
        try {
            $state = Cache::get(self::KEY);
            if (! is_array($state)) {
                return;
            }
            Cache::forget(self::KEY);
            if ($notify) {
                foreach (User::query()->pluck('id') as $userId) {
                    Notification::query()->create([
                        'user_id' => $userId,
                        'type' => 'ai_quota_pulih',
                        'title' => 'Kuota AI pulih',
                        'message' => 'Model '.($state['model'] ?? 'AI').' bisa dipakai lagi. Eksekusi yang dijeda karena kuota dapat dilanjutkan dengan tombol Lanjutkan.',
                        'link' => route('dashboard', [], false),
                    ]);
                }
            }
        } catch (Throwable) {
            // pencatatan kuota bersifat bantuan
        }
    }

    /** PO mengganti model aktif: peringatan untuk model lama tidak berlaku lagi (tanpa notifikasi pulih). */
    public static function clear(): void
    {
        try {
            Cache::forget(self::KEY);
        } catch (Throwable) {
            // abaikan
        }
    }

    /**
     * Tambahkan hasil pengawas (waktu pulih, jadwal cek) ke status yang ada.
     *
     * @param  array<string, mixed>  $patch
     */
    public static function annotate(array $patch): void
    {
        try {
            $state = Cache::get(self::KEY);
            if (is_array($state)) {
                self::store(array_merge($state, $patch));
            }
        } catch (Throwable) {
            // abaikan
        }
    }

    /** @param  array<string, mixed>  $state */
    private static function store(array $state): void
    {
        // Status bertahan sampai waktu pulih (kuota mingguan bisa berhari-hari), minimal 12 jam.
        $ttl = now()->addHours(12);
        if (! empty($state['reset_at'])) {
            $until = rescue(fn () => Carbon::parse($state['reset_at'])->addHour(), null, false);
            if ($until !== null && $until->greaterThan($ttl)) {
                $ttl = $until->greaterThan(now()->addDays(8)) ? now()->addDays(8) : $until;
            }
        }
        Cache::put(self::KEY, $state, $ttl);
    }

    /** @return array{model: string, since: string, last_seen: string, detail: string}|null */
    public static function current(): ?array
    {
        try {
            $current = Cache::get(self::KEY);
            if (! is_array($current)) {
                return null;
            }
            // Model aktif sudah diganti ke model lain: peringatan model lama hilang.
            $active = rescue(fn () => app(ActiveModelService::class)->current(), null, false);
            if (is_string($active) && trim($active) !== '' && self::normalize($active) !== self::normalize((string) ($current['model'] ?? ''))) {
                Cache::forget(self::KEY);

                return null;
            }

            return $current;
        } catch (Throwable) {
            return null;
        }
    }

    /** "scrumgateway/gemini-x" dan "gemini-x" dianggap model yang sama. */
    public static function normalize(string $model): string
    {
        $model = trim($model);

        return mb_strtolower(str_contains($model, '/') ? substr($model, strrpos($model, '/') + 1) : $model);
    }

    private static function modelFromDetail(string $detail): ?string
    {
        $plain = (string) preg_replace('/\e\[[0-9;]*m/', '', $detail);
        if (preg_match('/build\s*·\s*([A-Za-z0-9._\/-]+)/u', $plain, $m) || preg_match('/model=([A-Za-z0-9._\/-]+)/', $plain, $m)) {
            return $m[1];
        }

        return null;
    }

    private const ZONE = 'Asia/Jakarta';

    /** "6 hari 23 jam", "3 jam 12 menit", "14 menit". */
    public static function duration(int $seconds): string
    {
        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);
        $m = intdiv($seconds % 3600, 60);

        return match (true) {
            $d > 0 => $d.' hari '.$h.' jam',
            $h > 0 => $h.' jam '.$m.' menit',
            default => max(1, $m).' menit',
        };
    }

    /** "6 hari 23 jam lagi" atau "sebentar lagi" bila waktu sudah lewat. */
    public static function remaining(string $resetAt): string
    {
        $seconds = rescue(fn () => Carbon::parse($resetAt)->timestamp - time(), 0, false);

        return $seconds <= 0 ? 'sebentar lagi' : self::duration($seconds).' lagi';
    }

    public static function resetLabel(string $resetAt): string
    {
        return Carbon::parse($resetAt)->timezone(self::ZONE)->locale('id')->translatedFormat('l, d M Y H:i').' WIB';
    }

    /**
     * Bahan tampilan banner: apa yang terjadi dan sampai kapan harus menunggu.
     *
     * @return array<string, mixed>
     */
    public static function banner(array $state): array
    {
        $resetAt = $state['reset_at'] ?? null;
        $next = isset($state['next_check_at']) ? rescue(fn () => Carbon::parse($state['next_check_at'])->timezone(self::ZONE)->format('H:i'), null, false) : null;

        return [
            'headline' => 'Kuota AI habis',
            'detail' => 'Model '.$state['model'].' ditolak penyedia. Estimasi, eksekusi, dan chat Scrum Master berhenti sampai kuota pulih atau Anda mengganti model aktif.',
            'reset_at' => $resetAt,
            'reset_certain' => (bool) ($state['reset_certain'] ?? false),
            'reset_label' => $resetAt ? self::resetLabel($resetAt) : null,
            'remaining' => $resetAt ? self::remaining($resetAt) : null,
            'next_check' => $next,
        ];
    }

    /** Kalimat peringatan untuk PO (panel progres, notifikasi). */
    public static function message(array $state): string
    {
        $banner = self::banner($state);
        $since = rescue(fn () => Carbon::parse($state['since'])->timezone(self::ZONE)->format('H:i'), '');

        $text = 'Kuota AI habis: model '.$state['model'].' ditolak penyedia'.($since !== '' ? ' sejak '.$since.' WIB' : '').'. ';
        if ($banner['reset_at']) {
            $text .= 'Perkiraan pulih '.$banner['reset_label'].' ('.$banner['remaining'].'). ';
        } else {
            $text .= 'Waktu pulih belum diketahui; dicek otomatis'.($banner['next_check'] ? ', cek berikutnya '.$banner['next_check'].' WIB' : '').'. ';
        }

        return $text.'Estimasi, eksekusi, dan chat Scrum Master berhenti sampai kuota pulih atau Anda mengganti model aktif.';
    }
}
