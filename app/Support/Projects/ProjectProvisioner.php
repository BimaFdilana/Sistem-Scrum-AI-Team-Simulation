<?php

namespace App\Support\Projects;

use App\Models\Project;
use App\Models\ProjectProfile;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Menyiapkan project agar benar-benar bisa dijalankan: dependency, .env,
 * APP_KEY, storage link, database, dan seed.
 *
 * Sebelumnya langkah ini tidak ada sama sekali — project hasil clone hanya
 * diverifikasi strukturnya, lalu demo langsung dijalankan dan berakhir 500
 * karena `vendor/` tidak ada.
 */
class ProjectProvisioner
{
    public function __construct(private readonly ProjectProfiler $profiler) {}

    /** Idempoten: aman dipanggil berkali-kali (import, demo, retry manual). */
    public function ensure(Project $project, bool $force = false): ProjectProfile
    {
        $profile = $project->profile ?? $this->profiler->profile($project);

        // Status RUNNING yang tertinggal (worker mati/timeout) bukan bukti
        // provisioning selesai: setelah 10 menit tanpa update, ulangi.
        $staleRunning = $profile->provision_status === 'RUNNING'
            && $profile->updated_at !== null
            && $profile->updated_at->lt(now()->subMinutes(10));

        if (! $force && ! $staleRunning && $profile->isProvisioned() && $this->hasVendor($project, $profile)) {
            return $profile;
        }

        return $this->provision($project, $profile);
    }

    public function provision(Project $project, ?ProjectProfile $profile = null): ProjectProfile
    {
        $profile ??= $project->profile ?? $this->profiler->profile($project);
        $profile->forceFill(['provision_status' => 'RUNNING', 'provision_error' => null])->save();

        try {
            $appRoot = $this->appRoot($project, $profile);

            $this->prepareEnv($appRoot);
            $this->composerInstall($appRoot, $profile);
            $this->ensureAppKey($appRoot, $profile);
            $this->linkStorage($appRoot, $profile);
            $this->migrateDatabase($appRoot, $profile);

            // Siapkan DB demo sejak awal; kegagalan migrasi SQLite ketahuan di
            // sini, bukan saat PO menekan "Jalankan demo" di akhir.
            try {
                app(\App\Support\Demo\DemoServerManager::class)->prepareDatabase($project);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Persiapan DB demo gagal', ['project' => $project->id, 'error' => $e->getMessage()]);
            }

            $profile->forceFill([
                'provision_status' => 'READY',
                'provision_error' => null,
                'provisioned_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $profile->forceFill([
                'provision_status' => 'FAILED',
                'provision_error' => mb_substr($exception->getMessage(), 0, 1000),
            ])->save();

            throw $exception;
        }

        return $profile;
    }

    public function appRoot(Project $project, ProjectProfile $profile): string
    {
        $root = $this->profiler->rootFor($project);

        return $profile->subdir ? $root.'/'.$profile->subdir : $root;
    }

    public function hasVendor(Project $project, ProjectProfile $profile): bool
    {
        try {
            return is_dir($this->appRoot($project, $profile).'/vendor');
        } catch (Throwable) {
            return false;
        }
    }

    private function prepareEnv(string $appRoot): void
    {
        $env = $appRoot.'/.env';
        $example = $appRoot.'/.env.example';

        if (! is_file($env) && is_file($example)) {
            File::copy($example, $env);
        }

        if (! is_file($env)) {
            // Beberapa project menyimpan konfigurasi di config/ saja.
            return;
        }

        $content = (string) file_get_contents($env);
        $content = preg_replace('/^APP_ENV\s*=.*$/mi', 'APP_ENV=local', $content) ?? $content;
        $content = preg_replace('/^APP_DEBUG\s*=.*$/mi', 'APP_DEBUG=false', $content) ?? $content;
        file_put_contents($env, $content);
    }

    private function composerInstall(string $appRoot, ProjectProfile $profile): void
    {
        if (is_dir($appRoot.'/vendor')) {
            return;
        }

        $composer = $this->binary('composer');
        $php = $profile->php_binary ?: $this->binary('php');

        // Composer dijalankan dengan binary PHP milik project, bukan PHP
        // server: platform check & pemilihan paket harus mengikuti PHP
        // project, kalau tidak resolusi dependency salah versi.
        $hasLock = is_file($appRoot.'/composer.lock');
        $command = $hasLock
            ? [$php, $composer, 'install', '--no-interaction', '--prefer-dist', '--no-progress']
            : [$php, $composer, 'update', '--no-interaction', '--prefer-dist', '--no-progress'];

        $process = $this->run($command, $appRoot, 1200);
        if ($process->isSuccessful()) {
            return;
        }

        $output = $process->getOutput().$process->getErrorOutput();

        // Composer 2.10 memblokir paket yang punya security advisory. Untuk
        // project PO yang sudah berjalan, memblokir berarti demo tidak pernah
        // bisa disiapkan; jalankan dengan --no-blocking dan catat alasannya.
        if (preg_match('/security advisories|not loaded, because/i', $output)) {
            $retry = $this->run(array_merge($command, ['--no-blocking']), $appRoot, 1200);
            if ($retry->isSuccessful()) {
                $profile->forceFill([
                    'provision_error' => 'Dependency dipasang dengan --no-blocking: ada paket ber-advisory.',
                ])->save();

                return;
            }
            $output = $retry->getOutput().$retry->getErrorOutput();
        }

        // Konflik platform (Laravel lama vs PHP baru) adalah kasus nyata di
        // project ini. Retry sekali tanpa pemeriksaan platform, dan catat
        // peringatannya — lebih baik jalan dengan catatan daripada demo 500.
        if (preg_match('/platform|requires php|conflict/i', $output)) {
            $retry = $this->run(array_merge($command, ['--ignore-platform-reqs', '--no-blocking']), $appRoot, 1200);
            if ($retry->isSuccessful()) {
                $profile->forceFill([
                    'provision_error' => 'composer butuh --ignore-platform-reqs ('.$profile->php_version.' vs requirement composer).',
                ])->save();

                return;
            }
            $output = $retry->getOutput().$retry->getErrorOutput();
        }

        throw new RuntimeException('composer gagal: '.$this->tail($output));
    }

    private function ensureAppKey(string $appRoot, ProjectProfile $profile): void
    {
        $env = $appRoot.'/.env';
        if (! is_file($env)) {
            return;
        }

        $content = (string) file_get_contents($env);
        if (preg_match('/^APP_KEY\s*=\s*base64:.+/mi', $content)) {
            return;
        }

        // Ditulis langsung, bukan lewat `artisan key:generate`: perintah itu
        // pernah tidak mengubah .env walau keluar dengan status 0, dan demo
        // lalu gagal dengan "No application encryption key".
        $key = 'base64:'.base64_encode(random_bytes(32));

        if (preg_match('/^APP_KEY\s*=.*$/mi', $content)) {
            $content = preg_replace('/^APP_KEY\s*=.*$/mi', 'APP_KEY='.$key, $content, 1);
        } else {
            $content = rtrim($content, "\n")."\nAPP_KEY=".$key."\n";
        }

        file_put_contents($env, $content);
    }

    private function linkStorage(string $appRoot, ProjectProfile $profile): void
    {
        if (is_link($appRoot.'/public/storage') || is_dir($appRoot.'/public/storage')) {
            return;
        }

        // Sebagian project tidak memakai storage link; kegagalan di sini
        // tidak boleh menggagalkan provisioning.
        try {
            $this->artisan($appRoot, $profile, ['storage:link'], 60, false);
        } catch (Throwable) {
            // diabaikan
        }
    }

    private function migrateDatabase(string $appRoot, ProjectProfile $profile): void
    {
        $database = $appRoot.'/database/database.sqlite';
        if (! is_dir(dirname($database))) {
            return;
        }

        if (! is_file($database)) {
            touch($database);
        }

        $env = [
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $database,
            'DB_FOREIGN_KEYS' => 'true',
        ];

        try {
            $skipped = $this->migrateWithSqliteSkips($appRoot, $database, $profile, $env);
        } catch (Throwable $exception) {
            // Migrasi project yang bergantung pada MySQL tidak bisa dipaksa
            // ke SQLite; beri pesan jelas, bukan 500 saat demo.
            throw new RuntimeException('Migrasi database gagal pada runtime SQLite: '.$exception->getMessage());
        }

        if ($skipped !== []) {
            $profile->forceFill([
                'provision_error' => 'Migrasi MySQL-only dilewati saat demo SQLite: '.implode(', ', $skipped),
            ])->save();
        }

        // Seed bersifat best-effort: banyak project tidak punya seeder.
        try {
            $this->artisan($appRoot, $profile, ['db:seed', '--force', '--no-interaction'], 300, true, $env);
        } catch (Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('Seeder project gagal (diabaikan)', [
                'project_id' => $profile->project_id,
                'error' => mb_substr($exception->getMessage(), 0, 300),
            ]);
        }
    }

    /**
     * Jalankan migrasi SQLite dan lewati migrasi MySQL-only.
     *
     * Project nyata sering memakai raw `MODIFY`, `CHANGE COLUMN`, atau
     * `ENGINE=` yang tidak dikenal SQLite. Melewatinya lebih berguna daripada
     * menggagalkan seluruh demo, asal setiap lewatan dicatat di profil.
     *
     * @param  array<string, string>  $env
     * @return array<int, string> nama migrasi yang dilewati
     */
    private function migrateWithSqliteSkips(string $appRoot, string $database, ProjectProfile $profile, array $env): array
    {
        $skipped = [];
        $php = $profile->php_binary ?: $this->binary('php');

        for ($attempt = 1; $attempt <= 8; $attempt++) {
            $process = $this->run([$php, 'artisan', 'migrate', '--force', '--no-interaction'], $appRoot, 600, $env);

            if ($process->isSuccessful()) {
                return $skipped;
            }

            $output = $process->getOutput().$process->getErrorOutput();
            // Buang kode ANSI: Laravel mewarnai baris "Migrating:" sehingga
            // regex ^Migrating: gagal cocok dan semua kegagalan terlihat
            // seperti "tanpa nama migrasi".
            $plain = preg_replace('/\x1b\[[0-9;]*m/', '', $output) ?? $output;

            $failing = null;
            if (preg_match_all('/^Migrating:\s*(.+)$/m', $plain, $matches) && $matches[1] !== []) {
                $failing = trim((string) end($matches[1]));
            } elseif (preg_match('#database/migrations/([A-Za-z0-9_]+)\.php#', $plain, $match)) {
                $failing = $match[1];
            }

            if ($failing === null) {
                throw new RuntimeException('migrate gagal: '.$this->tail($plain));
            }

            $file = $appRoot.'/database/migrations/'.$failing.'.php';
            $sql = is_file($file) ? (string) file_get_contents($file) : '';

            // JANGAN pernah melewati migrasi yang MEMBUAT tabel: melewatinya
            // membuat tabel hilang dan semua migrasi berikutnya gagal
            // ("no such table"). Hanya perubahan kolom yang boleh dilewati.
            if (preg_match('/Schema::create\s*\(/i', $sql)) {
                throw new RuntimeException('migrate gagal pada '.$failing.' (membuat tabel, tidak boleh dilewati): '.$this->tail($plain));
            }

            // `->change()` (ubah default/tipe kolom) juga tidak didukung
            // SQLite pada banyak kasus, dan hanya menyangkut default nilai.
            if (! preg_match('/MODIFY|CHANGE\s+COLUMN|ENGINE\s*=|FULLTEXT|ALGORITHM\s*=|->change\s*\(/i', $sql)) {
                throw new RuntimeException('migrate gagal pada '.$failing.': '.$this->tail($plain));
            }

            try {
                $pdo = new \PDO('sqlite:'.$database);
                $batch = (int) $pdo->query('SELECT COALESCE(MAX(batch),0)+1 FROM migrations')->fetchColumn();
                $pdo->prepare('INSERT INTO migrations (migration, batch) VALUES (?, ?)')->execute([$failing, $batch]);
            } catch (Throwable $exception) {
                throw new RuntimeException('gagal menandai migrasi MySQL-only '.$failing.': '.$exception->getMessage());
            }

            $skipped[] = $failing;
        }

        throw new RuntimeException('terlalu banyak migrasi MySQL-only dilewati: '.implode(', ', $skipped));
    }

    /** @param array<int, string> $args */
    private function artisan(string $appRoot, ProjectProfile $profile, array $args, int $timeout, bool $required, array $extraEnv = []): void
    {
        $php = $profile->php_binary ?: $this->binary('php');
        $command = array_merge([$php, 'artisan'], $args);
        $process = $this->run($command, $appRoot, $timeout, $extraEnv);

        if ($process->isSuccessful() || ! $required) {
            return;
        }

        throw new RuntimeException(implode(' ', $args).' gagal: '.$this->tail($process->getOutput().$process->getErrorOutput()));
    }

    /** @param array<int, string> $command */
    private function run(array $command, string $cwd, int $timeout, array $extraEnv = []): Process
    {
        $env = array_merge([
            'HOME' => storage_path('app/private/.provision-home'),
            'COMPOSER_ALLOW_SUPERUSER' => '1',
            'COMPOSER_NO_INTERACTION' => '1',
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'APP_ENV' => 'local',
        ], $extraEnv);

        $process = new Process($command, $cwd, $env, null, $timeout);
        $process->run();

        return $process;
    }

    private function binary(string $name): string
    {
        foreach (['/usr/local/bin/'.$name, '/usr/bin/'.$name] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return $name;
    }

    private function tail(string $output): string
    {
        $lines = array_slice(array_filter(explode("\n", $output)), -6);

        return mb_substr(trim(implode(' | ', $lines)), 0, 600);
    }
}
