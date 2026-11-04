<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Satu model untuk SEMUA peran agent.
 *
 * Menggantikan halaman Konfigurasi Agent per-role (dihapus 3 Nov 2026 atas
 * permintaan PO). Model dipilih PO di halaman AI Providers, lalu disebar
 * ke tiga tempat yang sebelumnya bisa berbeda sendiri-sendiri:
 *
 *   1. `settings.active_model`  — sumber kebenaran aplikasi (dibaca
 *      StrictModelResolver),
 *   2. `agent_role_settings`    — baris per peran, dicerminkan agar data
 *      lama tetap konsisten saat diperiksa dari DB,
 *   3. config profile Hermes    — dipakai worker kanban, yang TIDAK
 *      membaca menu Laravel sama sekali.
 *
 * Poin 3 adalah alasan kelas ini ada. Sebelumnya ketiga tempat itu bisa
 * menunjuk model berbeda tanpa ada yang menyadari, dan itulah sumber
 * "gonta-ganti model" yang membingungkan: chat, estimasi, dan worker
 * kanban bisa menjawab dengan model yang tidak sama.
 */
class ActiveModelService
{
    public const SETTING_KEY = 'active_model';

    /** Slug peran Laravel -> nama profile Hermes (dari config/agents.php). */
    public function roles(): array
    {
        return collect(config('agents.profiles', []))
            ->mapWithKeys(fn (array $role) => [$role['slug'] => $role['profile'] ?? $role['slug']])
            ->all();
    }

    /** Model yang sedang aktif untuk semua peran. Null = belum dipilih. */
    public function current(): ?string
    {
        $model = Setting::get(self::SETTING_KEY);
        if (is_string($model) && trim($model) !== '') {
            return trim($model);
        }

        // Kompatibilitas: instalasi lama mungkin hanya punya baris per peran
        // (dari menu Konfigurasi Agent yang kini dihapus). Pakai nilai
        // pertama yang ditemukan supaya sistem tidak "kehilangan" pilihan
        // hanya karena menu itu hilang.
        return $this->modelFromRoleRows();
    }

    /**
     * Daftar model yang bisa dipilih, apa adanya dari gateway.
     *
     * @return array<int, string>
     */
    public function available(): array
    {
        try {
            $models = app(CliProxyApiClient::class)->models();
        } catch (Throwable $exception) {
            Log::warning('Gagal membaca daftar model gateway', ['error' => $exception->getMessage()]);

            return [];
        }

        $ids = [];
        foreach ($models as $model) {
            $id = is_array($model) ? ($model['id'] ?? null) : null;
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * Simpan model aktif dan sebarkan ke semua tempat.
     *
     * @return array{warnings: array<int, string>}
     *
     * @throws ValidationException bila model tidak ada di gateway
     */
    public function set(string $model): array
    {
        $model = trim($model);
        $available = $this->available();

        if ($model === '' || ! in_array($model, $available, true)) {
            throw ValidationException::withMessages([
                'active_model' => 'Model "'.$model.'" tidak ada di daftar model gateway saat ini. '
                    .'Hubungkan provider atau pilih model yang tersedia.',
            ]);
        }

        Setting::put(self::SETTING_KEY, $model);
        $this->mirrorToRoleRows($model);

        return ['warnings' => $this->syncHermesProfiles($model)];
    }

    /** Cerminkan ke baris per peran agar data lama tetap konsisten. */
    private function mirrorToRoleRows(string $model): void
    {
        try {
            foreach (array_keys($this->roles()) as $slug) {
                $updated = DB::table('agent_role_settings')
                    ->where('role', $slug)
                    ->update(['active_model' => $model]);

                if ($updated === 0) {
                    continue; // belum pernah ada konfigurasi peran itu; resolver memakai setting global
                }
            }
        } catch (Throwable $exception) {
            Log::warning('Gagal mencerminkan model aktif ke agent_role_settings', [
                'model' => $model,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function modelFromRoleRows(): ?string
    {
        try {
            $row = DB::table('agent_role_settings')
                ->whereNotNull('active_model')
                ->where('active_model', '!=', '')
                ->first();
        } catch (Throwable) {
            return null;
        }

        return $row?->active_model ?: null;
    }

    /**
     * Tulis model ke config profile Hermes (worker kanban membaca dari sini).
     *
     * @return array<int, string> peringatan untuk ditampilkan ke PO
     */
    private function syncHermesProfiles(string $model): array
    {
        $binary = (string) config('ai.hermes.binary', 'hermes');
        $warnings = [];
        $seen = [];

        foreach ($this->roles() as $slug => $profile) {
            if (isset($seen[$profile])) {
                continue;
            }
            $seen[$profile] = true;

            try {
                // Jalankan sebagai pemilik home Hermes: worker www-data tidak
                // punya akses ke ~/.hermes (mode 700) milik user lain.
                $command = [$binary, '-p', $profile, 'config', 'set', 'model.default', $model];
                $process = new Process($command, null, null, null, 60);
                $process->run();

                if (! $process->isSuccessful()) {
                    $warnings[] = 'Profile '.$profile.' gagal disetel ke '.$model.': '
                        .mb_substr(trim($process->getErrorOutput() ?: $process->getOutput()), 0, 200);
                }
            } catch (Throwable $exception) {
                $warnings[] = 'Profile '.$profile.' gagal disetel ke '.$model.': '.$exception->getMessage();
            }
        }

        return $warnings;
    }
}
