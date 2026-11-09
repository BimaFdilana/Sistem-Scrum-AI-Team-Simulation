<?php

namespace App\Support\Projects;

use App\Models\Project;
use App\Models\ProjectProfile;
use RuntimeException;

/**
 * Mendeteksi kebutuhan runtime sebuah project Laravel dari isi repo-nya.
 *
 * Tujuan: aplikasi berhenti mengasumsikan satu PHP untuk semua project.
 * Hasil deteksi disimpan di `project_profiles` dan dipakai oleh provisioner,
 * worker, dan demo.
 */
class ProjectProfiler
{
    /** Kandidat binary PHP yang dicari di /usr/bin. */
    private const PHP_CANDIDATES = ['8.5', '8.4', '8.3', '8.2', '8.1', '8.0'];

    public function profile(Project $project): ProjectProfile
    {
        $root = $this->rootFor($project);
        $subdir = $this->detectSubdir($root);
        $appRoot = $subdir === null ? $root : $root.'/'.$subdir;

        $laravelVersion = $this->laravelVersion($appRoot);
        $constraint = $this->phpConstraint($appRoot);
        [$binary, $phpVersion] = $this->pickPhp($constraint);

        if ($binary === null) {
            throw new RuntimeException(
                'Project membutuhkan PHP dengan constraint "'.($constraint ?: 'tidak diketahui').
                '", tetapi tidak ada binary yang cocok terpasang di server.'
            );
        }

        return ProjectProfile::query()->updateOrCreate(
            ['project_id' => $project->id],
            [
                'php_binary' => $binary,
                'php_version' => $phpVersion,
                'laravel_version' => $laravelVersion,
                'db_engine' => $this->dbEngine($appRoot),
                'needs_node' => is_file($appRoot.'/package.json'),
                'subdir' => $subdir,
                'provision_status' => 'PENDING',
                'provision_error' => null,
            ]
        );
    }

    public function rootFor(Project $project): string
    {
        $root = rtrim((string) $project->workspace_path, '/');
        if ($root === '' || ! is_dir($root)) {
            throw new RuntimeException('Baseline project belum ada di server.');
        }

        return $root;
    }

    /** Laravel bisa berada di root repo atau satu level di bawahnya (monorepo). */
    public function detectSubdir(string $root): ?string
    {
        if (is_file($root.'/artisan') && is_file($root.'/composer.json')) {
            return null;
        }

        foreach (glob($root.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (is_file($dir.'/artisan') && is_file($dir.'/composer.json')) {
                return basename($dir);
            }
        }

        return null;
    }

    public function laravelVersion(string $appRoot): ?string
    {
        $lock = $appRoot.'/composer.lock';
        if (is_file($lock)) {
            $data = json_decode((string) @file_get_contents($lock), true);
            foreach (array_merge($data['packages'] ?? [], $data['packages-dev'] ?? []) as $package) {
                if (($package['name'] ?? null) === 'laravel/framework') {
                    return ltrim((string) ($package['version'] ?? ''), 'v') ?: null;
                }
            }
        }

        $composer = $this->composerJson($appRoot);

        return $composer['require']['laravel/framework'] ?? null;
    }

    public function phpConstraint(string $appRoot): ?string
    {
        $composer = $this->composerJson($appRoot);

        return $composer['require']['php'] ?? null;
    }

    /**
     * Pilih binary PHP tertinggi yang memenuhi constraint composer.
     * Constraint yang tidak bisa dibaca (mis. ">=7.3|^8.0") dianggap tanpa
     * batas atas, sehingga PHP terbaru yang tersedia dipakai.
     *
     * @return array{0: ?string, 1: ?string} [binary, versi]
     */
    public function pickPhp(?string $constraint): array
    {
        $installed = array_values(array_filter(self::PHP_CANDIDATES, function (string $version): bool {
            return is_executable('/usr/bin/php'.$version);
        }));

        if ($installed === []) {
            $fallback = CliPhp::path();

            return [$fallback, PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION];
        }

        $min = null;
        $maxExclusive = null;

        if ($constraint !== null) {
            if (preg_match_all('/8\.(\d+)/', $constraint, $matches)) {
                $min = min(array_map('intval', $matches[1]));
            }
            if (preg_match('/<\s*=?\s*8\.(\d+)/', $constraint, $upper)) {
                $maxExclusive = (int) $upper[1];
            }
        }

        // Pilih versi TERENDAH yang memenuhi constraint: project lama lebih
        // sering rusak di PHP terbaru daripada sebaliknya.
        sort($installed, SORT_NATURAL);

        foreach ($installed as $version) {
            $minor = (int) substr($version, 2);
            if ($min !== null && $minor < $min) {
                continue;
            }
            if ($maxExclusive !== null && $minor >= $maxExclusive) {
                continue;
            }

            return ['/usr/bin/php'.$version, $version];
        }

        // Tidak ada binary yang memenuhi constraint. Jangan diam-diam memakai
        // versi di luar rentang (itu berakhir pada kegagalan platform yang
        // membingungkan di composer); laporkan supaya bisa ditindaklanjuti.
        return [null, null];
    }

    public function dbEngine(string $appRoot): string
    {
        foreach (['.env', '.env.example'] as $file) {
            $path = $appRoot.'/'.$file;
            if (! is_file($path)) {
                continue;
            }
            if (preg_match('/^DB_CONNECTION\s*=\s*([a-z0-9_-]+)/mi', (string) @file_get_contents($path), $match)) {
                return strtolower($match[1]);
            }
        }

        return 'sqlite';
    }

    /** @return array<string, mixed> */
    private function composerJson(string $appRoot): array
    {
        $path = $appRoot.'/composer.json';
        if (! is_file($path)) {
            return [];
        }

        return json_decode((string) @file_get_contents($path), true) ?: [];
    }
}
