<?php

namespace App\Support\Ai;

use App\Models\Notification;
use App\Models\User;
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
            $model = $model ?: 'model aktif';
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

    /** Model berhasil dipanggil lagi: peringatan kuota untuk model itu dihapus. */
    public static function recovered(?string $model): void
    {
        try {
            $current = Cache::get(self::KEY);
            if (is_array($current) && ($model === null || ($current['model'] ?? null) === $model)) {
                Cache::forget(self::KEY);
            }
        } catch (Throwable) {
            // pencatatan kuota bersifat bantuan
        }
    }

    /** @return array{model: string, since: string, last_seen: string, detail: string}|null */
    public static function current(): ?array
    {
        try {
            $current = Cache::get(self::KEY);

            return is_array($current) ? $current : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Kalimat peringatan untuk PO. */
    public static function message(array $state): string
    {
        $since = rescue(fn () => Carbon::parse($state['since'])->timezone(config('app.timezone'))->format('H:i'), '');

        return 'Kuota AI habis: model '.$state['model'].' ditolak penyedia'.($since !== '' ? ' sejak '.$since : '')
            .'. Estimasi, eksekusi, dan chat Scrum Master berhenti sampai kuota pulih atau Anda mengganti model aktif.';
    }
}
