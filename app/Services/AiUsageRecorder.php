<?php

namespace App\Services;

use App\Models\AiUsageRecord;
use Throwable;

/**
 * Pencatatan pemakaian token AI per panggilan gateway yang berhasil.
 *
 * Gateway CLIProxyAPI tidak menyediakan angka limit/kuota resmi yang
 * bisa dibaca, sehingga halaman AI Providers mengisi progress bar
 * dari pencatatan sendiri ini: jumlah token yang benar-benar dipakai
 * ScrumSpace (30 hari terakhir, per provider dan per model). Bar
 * menunjukkan proporsi antar provider, BUKAN persentase terhadap
 * limit resmi — karena limit resminya tidak diketahui.
 * Pencatatan best-effort: gagal catat tidak boleh menggagalkan chat.
 */
class AiUsageRecorder
{
    public static function record(?string $model, array $usage): void
    {
        $total = (int) ($usage['total_tokens'] ?? 0);
        if ($model === null || $model === '' || $total <= 0) {
            return;
        }
        try {
            AiUsageRecord::query()->create([
                'provider' => self::providerForModel($model),
                'model' => $model,
                'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                'total_tokens' => $total,
            ]);
        } catch (Throwable) {
            // Best-effort saja.
        }
    }

    public static function providerForModel(string $model): string
    {
        $lower = strtolower($model);
        if (str_contains($lower, 'gemini') || str_contains($lower, 'google')) {
            return 'Google';
        }
        if (str_contains($lower, 'claude') || str_contains($lower, 'anthropic')) {
            return 'Anthropic';
        }
        if (str_contains($lower, 'gpt') || str_contains($lower, 'codex') || str_contains($lower, 'openai') || str_starts_with($lower, 'o1') || str_starts_with($lower, 'o3') || str_starts_with($lower, 'o4')) {
            return 'OpenAI';
        }

        return 'Lainnya';
    }

    /**
     * Agregat 30 hari terakhir: per provider (total token + jumlah
     * panggilan) dan per model. Dipakai halaman AI Providers.
     *
     * @return array{providers: array<int,array{provider:string,total:int,calls:int}>, models: array<int,array{model:string,provider:string,total:int,calls:int}>, grand_total:int}
     */
    public static function summary(int $days = 30): array
    {
        $since = now()->subDays($days);
        $rows = AiUsageRecord::query()->where('created_at', '>=', $since)->get();
        $providers = [];
        $models = [];
        foreach ($rows as $row) {
            $provider = $row->provider ?? 'Lainnya';
            $providers[$provider] ??= ['provider' => $provider, 'total' => 0, 'calls' => 0];
            $providers[$provider]['total'] += $row->total_tokens;
            $providers[$provider]['calls']++;
            $models[$row->model] ??= ['model' => $row->model, 'provider' => $provider, 'total' => 0, 'calls' => 0];
            $models[$row->model]['total'] += $row->total_tokens;
            $models[$row->model]['calls']++;
        }
        usort($providers, fn ($a, $b) => $b['total'] <=> $a['total']);
        usort($models, fn ($a, $b) => $b['total'] <=> $a['total']);

        return [
            'providers' => array_values($providers),
            'models' => array_values($models),
            'grand_total' => array_sum(array_column($providers, 'total')),
        ];
    }
}
