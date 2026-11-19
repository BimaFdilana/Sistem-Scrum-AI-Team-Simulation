<?php

namespace App\Services;

use App\Exceptions\ModelNotChosenException;
use Throwable;

/**
 * Satu-satunya sumber kebenaran untuk "model apa yang boleh dipakai
 * untuk satu role" (aturan tanpa-default Bima 2026-10-31):
 *
 * HANYA model yang dipilih PO secara eksplisit di halaman Konfigurasi
 * Agent yang boleh dipakai — snapshot AgentConfiguration terverifikasi
 * DAN modelnya masih ada di daftar model gateway saat ini. Tidak ada
 * fallback diam-diam apa pun: bukan HERMES_MODEL, bukan pemetaan
 * otomatis per provider, bukan model "terpintar", bukan model default
 * dari file config. Bila syarat tidak terpenuhi, role itu dianggap
 * belum memilih: pemanggil WAJIB menghentikan alur dengan peringatan
 * yang terlihat PO, bukan mengarang model pengganti.
 *
 * Dipakai semua jalur: adapter Hermes, SM/breakdown, review QA,
 * estimasi, chat agen, dan driver internal — supaya tidak ada lagi
 * jalur yang diam-diam memakai model yang tidak dipilih siapa pun
 * ("ghost error" seperti insiden pin kimi dan default glm).
 */
class StrictModelResolver
{
    public const STATE_OK = 'ok';

    public const STATE_NOT_CHOSEN = 'not_chosen';

    public const STATE_NOT_VERIFIED = 'not_verified';

    public const STATE_NOT_AVAILABLE = 'not_available';

    /**
     * @return array{model: ?string, state: string, message: string, short_reason: string}
     */
    public function check(string $role): array
    {
        try {
            $available = $this->availableModelIds();
        } catch (Throwable) {
            // Lapisan gateway tidak terbaca = dianggap belum memilih;
            // jangan pernah jatuh ke fallback diam-diam.
            return [
                'model' => null,
                'state' => self::STATE_NOT_CHOSEN,
                'message' => 'Daftar model gateway tidak terbaca — alur tidak dijalankan.',
                'short_reason' => 'Daftar model gateway tidak terbaca.',
            ];
        }

        // Model per peran (pengganti peran, lalu model global) yang dipilih PO
        // di halaman AI Model. Tidak ada model cadangan diam-diam.
        try {
            $model = app(ActiveModelService::class)->forRole($role);
        } catch (Throwable) {
            $model = null;
        }

        if ($model === null) {
            return [
                'model' => null,
                'state' => self::STATE_NOT_CHOSEN,
                'message' => 'Model AI belum dipilih di halaman AI Model — alur tidak dijalankan.',
                'short_reason' => 'Model belum dipilih di halaman AI Model.',
            ];
        }

        if ($available !== [] && ! in_array($model, $available, true)) {
            return [
                'model' => null,
                'state' => self::STATE_NOT_AVAILABLE,
                'message' => "Model {$model} yang dipilih tidak tersedia di akun Antigravity saat ini — perbarui pilihan di halaman AI Model, alur tidak dijalankan.",
                'short_reason' => 'Model yang dipilih tidak tersedia di akun Antigravity.',
            ];
        }

        return ['model' => $model, 'state' => self::STATE_OK, 'message' => '', 'short_reason' => ''];
    }

    public function resolve(string $role): ?string
    {
        return $this->check($role)['model'];
    }

    /**
     * @throws ModelNotChosenException
     */
    public function require(string $role): string
    {
        $check = $this->check($role);
        if ($check['model'] === null) {
            throw new ModelNotChosenException($check['message']);
        }

        return $check['model'];
    }

    public function notChosenException(string $role): ModelNotChosenException
    {
        return new ModelNotChosenException($this->check($role)['message']);
    }

    public function displayName(string $role): string
    {
        return match ($role) {
            'frontend-developer' => 'Frontend Developer',
            'backend-developer' => 'Backend Developer',
            'qa-engineer' => 'QA Engineer',
            'scrum-master' => 'Scrum Master',
            default => $role,
        };
    }

    /** @return string[] */
    public function availableModelIds(): array
    {
        try {
            return app(AntigravityClient::class)->modelIds();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Pesan penggantian MODEL_PROBLEMATIC (aturan Bima 2026-10-31):
     * menyebut model yang gagal, menyatakan model itu bermasalah,
     * mencantumkan daftar model available saat ini, dan memerintahkan
     * PO mengganti pilihan di halaman Konfigurasi Agent lalu retry.
     * Pesan ini BUKAN substitusi: tidak ada model lain yang dipakai
     * diam-diam — PO yang memilih penggantinya.
     */
    public function modelProblemMessage(string $model): string
    {
        $available = $this->availableModelIds();
        $list = $available === [] ? 'tidak dapat dimuat saat ini' : implode(', ', array_slice($available, 0, 30));

        return "MODEL_PROBLEMATIC: model {$model} bermasalah dan tidak bisa dipakai (tidak dikenali atau tidak tersedia di akun Antigravity). Sistem TIDAK menggantinya secara diam-diam ke model lain. Model yang tersedia saat ini: {$list}. Silakan ganti pilihan model di halaman AI Model, lalu ulangi (retry) alur ini.";
    }
}
