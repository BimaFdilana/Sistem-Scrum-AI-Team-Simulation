<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Key-value store untuk pengaturan sensitif (mis. GitHub PAT).
 * Nilai dienkripsi saat disimpan (butuh APP_KEY yang valid).
 */
class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    protected $casts = [
        'value' => 'encrypted',
    ];

    public $timestamps = true;

    /**
     * Ambil nilai setting (terdekripsi otomatis). null bila tidak ada.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        $row = static::query()->where('key', $key)->first();

        return $row ? (string) $row->value : $default;
    }

    /**
     * Simpan nilai setting (dienkripsi otomatis).
     */
    public static function put(string $key, string $value): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value],
        );
    }

    /**
     * Hapus setting.
     */
    public static function forget(string $key): void
    {
        static::query()->where('key', $key)->delete();
    }
}
