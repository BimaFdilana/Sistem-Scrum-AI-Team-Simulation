<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Validation\ValidationException;

/**
 * Model Antigravity yang dipakai tiap peran agen.
 *
 * Ada satu model global (`settings.active_model`) dan pengganti opsional per
 * peran (`settings.role_model.<slug>`). Peran tanpa pengganti memakai model
 * global. Daftar model yang sah selalu dibaca dari akun Antigravity yang
 * sedang login, sehingga PO tidak bisa memilih model yang tidak tersedia.
 */
class ActiveModelService
{
    public const SETTING_KEY = 'active_model';

    public const ROLE_SETTING_PREFIX = 'role_model.';

    /**
     * Slug peran -> nama peran untuk ditampilkan.
     *
     * @return array<string, string>
     */
    public function roles(): array
    {
        return collect(config('agents.profiles', []))
            ->mapWithKeys(fn (array $role) => [$role['slug'] => $role['name'] ?? $role['slug']])
            ->all();
    }

    /** Model global untuk semua peran. Null = belum dipilih. */
    public function current(): ?string
    {
        return $this->clean(Setting::get(self::SETTING_KEY));
    }

    /** Pengganti khusus satu peran, atau null bila peran itu ikut model global. */
    public function roleOverride(string $role): ?string
    {
        return $this->clean(Setting::get(self::ROLE_SETTING_PREFIX.$role));
    }

    /** Model yang berlaku untuk satu peran: pengganti peran, lalu model global. */
    public function forRole(string $role): ?string
    {
        return $this->roleOverride($role) ?? $this->current();
    }

    /**
     * Daftar model yang bisa dipilih, dari akun Antigravity yang login.
     *
     * @return array<int, string>
     */
    public function available(bool $fresh = false): array
    {
        return app(AntigravityClient::class)->modelIds($fresh);
    }

    /**
     * Simpan model global.
     *
     * @return array{warnings: array<int, string>}
     *
     * @throws ValidationException bila model tidak tersedia di akun
     */
    public function set(string $model): array
    {
        $model = $this->requireAvailable($model, 'active_model');
        Setting::put(self::SETTING_KEY, $model);

        return ['warnings' => []];
    }

    /**
     * Simpan atau hapus pengganti model untuk satu peran.
     *
     * @throws ValidationException bila peran tidak dikenal atau model tidak tersedia
     */
    public function setForRole(string $role, ?string $model): void
    {
        if (! array_key_exists($role, $this->roles())) {
            throw ValidationException::withMessages(['role' => 'Peran "'.$role.'" tidak dikenal.']);
        }

        $model = $this->clean($model);
        if ($model === null) {
            Setting::forget(self::ROLE_SETTING_PREFIX.$role);

            return;
        }

        Setting::put(self::ROLE_SETTING_PREFIX.$role, $this->requireAvailable($model, 'role_models.'.$role));
    }

    private function requireAvailable(string $model, string $field): string
    {
        $model = trim($model);
        if ($model === '' || ! in_array($model, $this->available(), true)) {
            throw ValidationException::withMessages([
                $field => 'Model "'.$model.'" tidak tersedia di akun Antigravity saat ini. '
                    .'Pilih model dari daftar, atau perbarui daftar model.',
            ]);
        }

        return $model;
    }

    private function clean(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
