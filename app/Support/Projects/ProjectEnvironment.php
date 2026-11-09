<?php

namespace App\Support\Projects;

/**
 * Lingkungan proses anak yang menjalankan KODE PROJECT (demo, tes, composer/npm).
 *
 * Pekerja antrean ScrumSpace memuat .env-nya ke lingkungan proses, sehingga anak
 * proses mewarisi APP_KEY, token GitHub, dan kunci gateway. Itu membocorkan rahasia
 * ke kode yang dihasilkan AI, dan variabel lingkungan nyata mengalahkan .env milik
 * project sehingga project memakai APP_KEY/DB milik ScrumSpace.
 */
class ProjectEnvironment
{
    /** Awalan konfigurasi Laravel/ScrumSpace yang tidak boleh bocor ke project. */
    private const PREFIXES = [
        'APP_', 'DB_', 'CACHE_', 'SESSION_', 'QUEUE_', 'REDIS_', 'MAIL_', 'BROADCAST_', 'FILESYSTEM_', 'LOG_', 'AWS_',
        'GITHUB_', 'GH_', 'CLIPROXY_', 'HERMES_', 'OPENCODE_', 'WEBHOOK_', 'DEMO_', 'EXECUTION_', 'AI_', 'BCRYPT_',
        'PULSE_', 'TELESCOPE_', 'NIGHTWATCH_', 'SANCTUM_',
    ];

    private const SENSITIVE_PATTERN = '/(KEY|TOKEN|SECRET|PASSWORD|PASSWD|CREDENTIAL)/i';

    /**
     * Daftar untuk Symfony Process: nilai false menghapus variabel dari lingkungan
     * yang diwarisi. Isi $extra ditimpakan paling akhir (mis. DB_DATABASE demo).
     *
     * @param  array<string, string>  $extra
     * @return array<string, string|false>
     */
    public static function scrubbed(array $extra = []): array
    {
        $env = [];
        foreach (array_keys(getenv()) as $name) {
            if (self::isSensitive((string) $name)) {
                $env[$name] = false;
            }
        }

        return array_merge($env, $extra);
    }

    public static function isSensitive(string $name): bool
    {
        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return preg_match(self::SENSITIVE_PATTERN, $name) === 1;
    }
}
