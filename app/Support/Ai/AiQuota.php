<?php

namespace App\Support\Ai;

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
            Cache::put(self::KEY, [
                'model' => $model,
                'since' => $isNew ? now()->toIso8601String() : ($current['since'] ?? now()->toIso8601String()),
                'last_seen' => now()->toIso8601String(),
                'detail' => Str::limit(trim((string) $detail), 300),
            ], now()->addHours(12));

            if ($isNew) {
                Log::warning('Kuota model AI habis', ['model' => $model, 'detail' => Str::limit((string) $detail, 300)]);
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
    public static function recovered(?string $model = null): void
    {
        try {
            if (Cache::has(self::KEY)) {
                Cache::forget(self::KEY);
            }
        } catch (Throwable) {
            // pencatatan kuota bersifat bantuan
        }
    }

    /** PO mengganti model aktif: peringatan untuk model lama tidak berlaku lagi. */
    public static function clear(): void
    {
        self::recovered();
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

    /** Kalimat peringatan untuk PO. */
    public static function message(array $state): string
    {
        $since = rescue(fn () => Carbon::parse($state['since'])->timezone(config('app.timezone'))->format('H:i'), '');

        return 'Kuota AI habis: model '.$state['model'].' ditolak penyedia'.($since !== '' ? ' sejak '.$since : '')
            .'. Estimasi, eksekusi, dan chat Scrum Master berhenti sampai kuota pulih atau Anda mengganti model aktif.';
    }
}
