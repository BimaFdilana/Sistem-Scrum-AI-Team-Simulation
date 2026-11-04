<?php

namespace App\Support\Demo;

use App\Models\DemoServer;
use App\Models\Project;
use RuntimeException;

/**
 * Menjalankan demo sementara project (php -S) di port unik per project.
 */
class DemoServerManager
{
    public function runningFor(Project $project): ?DemoServer
    {
        $demo = DemoServer::query()->where('project_id', $project->id)
            ->where('status', 'RUNNING')->latest()->first();
        if (! $demo) {
            return null;
        }
        if ($demo->expires_at?->isPast() || ! $demo->isAlive()) {
            $this->stop($demo, 'EXPIRED');

            return null;
        }

        return $demo;
    }

    public function start(Project $project): DemoServer
    {
        if ($this->runningFor($project)) {
            throw new RuntimeException('Demo project ini sudah berjalan.');
        }

        // Fase 3 GitHub: bila project terhubung GitHub dan branch integration ada,
        // demo jalan dari kode integration terbaru (worktree terpisah).
        // Bila tidak, perilaku lama: dari baseline lokal.
        $worktreePath = $this->prepareIntegrationWorktree($project);
        $docroot = $worktreePath !== null
            ? rtrim($worktreePath, '/').'/public'
            : rtrim((string) $project->workspace_path, '/').'/public';

        if (! is_dir($docroot)) {
            // Bersihkan worktree bila docroot tidak valid.
            if ($worktreePath !== null) {
                $this->removeWorktree($project, $worktreePath);
            }
            throw new RuntimeException('Baseline project belum siap untuk demo.');
        }

        $projectRoot = $worktreePath !== null ? rtrim($worktreePath, '/') : rtrim((string) $project->workspace_path, '/');
        $port = $this->allocatePort();
        $log = storage_path('logs/demo-project-'.$project->id.'.log');

        // Database demo memakai SQLite lokal: host demo tidak menyediakan
        // MySQL, sementara project PO sering dikonfigurasi DB_CONNECTION=mysql.
        // Env proses MENANG atas .env karena Dotenv bersifat immutable.
        $demoEnv = $this->demoEnvironment($projectRoot, $project, $log);

        $envPrefix = '';
        foreach ($demoEnv as $key => $value) {
            $envPrefix .= ' '.escapeshellarg($key.'='.$value);
        }

        $cmd = sprintf(
            'nohup env%s php -S 0.0.0.0:%d -t %s > %s 2>&1 & echo $!',
            $envPrefix, $port, escapeshellarg($docroot), escapeshellarg($log)
        );
        $pid = (int) trim((string) shell_exec($cmd));
        if ($pid <= 0) {
            if ($worktreePath !== null) {
                $this->removeWorktree($project, $worktreePath);
            }
            throw new RuntimeException('Gagal menjalankan server demo.');
        }

        // Jangan klaim RUNNING hanya karena proses hidup: pastikan endpoint
        // benar-benar menjawab (dulu demo "RUNNING" padahal HTTP 500 karena
        // vendor/DB tidak siap).
        $healthError = $this->waitForHealthy($port, $pid, $log);
        if ($healthError !== null) {
            @posix_kill($pid, 9);
            if ($worktreePath !== null) {
                $this->removeWorktree($project, $worktreePath);
            }

            throw new RuntimeException($healthError);
        }

        return DemoServer::query()->create([
            'project_id' => $project->id,
            'port' => $port,
            'pid' => $pid,
            'status' => 'RUNNING',
            'started_at' => now(),
            'expires_at' => now()->addMinutes((int) config('demo.ttl_minutes', 60)),
            'worktree_path' => $worktreePath,
        ]);
    }

    /**
     * Env proses demo: paksa SQLite lokal + driver file agar tidak butuh
     * MySQL/Redis di host demo. Migrasi dijalankan sekali saat DB masih kosong.
     *
     * @return array<string, string>
     */
    protected function demoEnvironment(string $projectRoot, Project $project, string $log): array
    {
        $dir = storage_path('app/demo-db');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $database = $dir.'/project-'.$project->id.'.sqlite';
        if (! file_exists($database)) {
            @touch($database);
        }

        $env = [
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $database,
            'DB_FOREIGN_KEYS' => 'true',
            'SESSION_DRIVER' => 'file',
            'CACHE_DRIVER' => 'file',
            'QUEUE_CONNECTION' => 'sync',
            'APP_ENV' => 'local',
        ];

        // Migrasi hanya saat DB masih kosong (file 0 byte / tanpa tabel).
        if (filesize($database) === 0) {
            $this->migrateDemoDatabase($projectRoot, $database, $env, $log);
            $this->seedDemoDatabase($projectRoot, $env, $log);
        }

        return $env;
    }

    /**
     * Migrasi SQLite untuk demo. Sebagian project PO memakai migrasi
     * MySQL-only (raw `MODIFY`); migrasi seperti itu ditandai sudah jalan
     * dan dilewati agar demo tetap bisa dibuka. Maksimum 10 putaran.
     *
     * @param  array<string, string>  $env
     */
    private function migrateDemoDatabase(string $projectRoot, string $database, array $env, string $log): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $process = new \Symfony\Component\Process\Process(
                [PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction'],
                $projectRoot,
                array_merge(getenv(), $env),
                null,
                300
            );
            $process->run();
            $output = $process->getOutput().$process->getErrorOutput();

            file_put_contents(
                $log,
                '['.date('c')."] migrate #{$attempt}: exit=".$process->getExitCode()."\n".mb_substr($output, -4000)."\n",
                FILE_APPEND
            );

            if ($process->isSuccessful()) {
                return;
            }

            if (! preg_match_all('/^Migrating:\s*(.+)$/m', $output, $matches) || $matches[1] === []) {
                throw new RuntimeException('Demo tidak bisa disiapkan: migrasi SQLite gagal tanpa nama migrasi (lihat '.$log.').');
            }

            $failing = trim((string) end($matches[1]));

            // Hanya lewati migrasi yang jelas MySQL-only (MODIFY/CHANGE/ENGINE).
            if (! preg_match('/MODIFY|CHANGE\s+COLUMN|ENGINE\s*=/i', $output)) {
                throw new RuntimeException('Demo tidak bisa disiapkan: migrasi "'.$failing.'" gagal di SQLite (lihat '.$log.').');
            }

            try {
                $pdo = new \PDO('sqlite:'.$database);
                $batch = (int) $pdo->query('SELECT COALESCE(MAX(batch),0)+1 FROM migrations')->fetchColumn();
                $statement = $pdo->prepare('INSERT INTO migrations (migration, batch) VALUES (?, ?)');
                $statement->execute([$failing, $batch]);
            } catch (\Throwable $exception) {
                throw new RuntimeException('Demo tidak bisa disiapkan: gagal menandai migrasi MySQL-only ('.$exception->getMessage().').');
            }

            file_put_contents(
                $log,
                '['.date('c')."] SKIP migrasi MySQL-only: {$failing}\n",
                FILE_APPEND
            );
        }

        throw new RuntimeException('Demo tidak bisa disiapkan: terlalu banyak migrasi MySQL-only dilewati (lihat '.$log.').');
    }

    /**
     * Isi data awal (setting/role/admin) agar halaman demo tidak error
     * karena tabel kosong. Gagal seeding TIDAK menggagalkan demo: sebagian
     * project memang tidak punya seeder.
     *
     * @param  array<string, string>  $env
     */
    private function seedDemoDatabase(string $projectRoot, array $env, string $log): void
    {
        $process = new \Symfony\Component\Process\Process(
            [PHP_BINARY, 'artisan', 'db:seed', '--force', '--no-interaction'],
            $projectRoot,
            array_merge(getenv(), $env),
            null,
            240
        );
        $process->run();

        file_put_contents(
            $log,
            '['.date('c')."] db:seed exit=".$process->getExitCode()."\n".mb_substr($process->getOutput().$process->getErrorOutput(), -2000)."\n",
            FILE_APPEND
        );
    }

    /**
     * Tunggu endpoint demo menjawab. Null = sehat; string = pesan kegagalan.
     * 2xx/3xx dianggap sehat (halaman login juga valid), 5xx/putus = gagal.
     */
    protected function waitForHealthy(int $port, int $pid, string $log): ?string
    {
        $url = 'http://127.0.0.1:'.$port.'/';

        for ($attempt = 1; $attempt <= 20; $attempt++) {
            usleep(500_000);

            if (! $this->pidAlive($pid)) {
                return 'Server demo berhenti saat start. Log: '.$this->logTail($log);
            }

            try {
                $response = \Illuminate\Support\Facades\Http::timeout(5)->get($url);
                $status = $response->status();

                if ($status < 500) {
                    return null;
                }

                if ($attempt >= 4) {
                    return 'Demo menjawab HTTP '.$status.' — project gagal boot. Log: '.$this->logTail($log);
                }
            } catch (\Throwable) {
                // Belum siap (connection refused) — coba lagi.
            }
        }

        return 'Demo tidak menjawab di '.$url.' setelah 10 detik. Log: '.$this->logTail($log);
    }

    /** Ambil 2 baris terakhir log demo untuk pesan error. */
    private function logTail(string $log): string
    {
        if (! is_file($log)) {
            return '(log tidak ada)';
        }

        $lines = array_slice(array_filter(explode("\n", (string) @file_get_contents($log))), -2);

        return mb_substr(trim(implode(' | ', $lines)), 0, 300);
    }

    /**
     * Fase 3 GitHub: siapkan worktree dari branch integration terbaru.
     * Return path worktree, atau null bila GitHub tidak dipakai/tidak ada integration.
     */
    protected function prepareIntegrationWorktree(Project $project): ?string
    {
        try {
            if (! filled($project->github_owner) || ! filled($project->github_repo)) {
                return null;
            }

            $service = app(\App\Services\GithubProjectService::class);
            if (! $service->isConnected()) {
                return null;
            }

            $github = app(\App\Services\GithubApiClient::class);
            if ($github->branchSha($project->github_owner, $project->github_repo, 'integration') === null) {
                return null; // Belum ada integration — pakai baseline lokal.
            }

            $workspace = (string) $project->workspace_path;
            if ($workspace === '' || ! is_dir($workspace)) {
                return null;
            }

            // Sinkronkan branch integration lokal dengan remote.
            $service->syncBranchFromRemote($workspace, 'integration');

            // Buat worktree terpisah agar workspace utama tidak terganggu.
            $worktreePath = storage_path('app/demo-worktrees/'.$project->id);
            $this->removeWorktree($project, $worktreePath); // Bersihkan sisa lama.
            $this->git($workspace, ['worktree', 'add', '--detach', $worktreePath, 'integration']);

            return $worktreePath;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Demo worktree integration gagal, pakai baseline', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Hapus worktree demo (best-effort).
     */
    protected function removeWorktree(Project $project, string $worktreePath): void
    {
        try {
            $workspace = (string) $project->workspace_path;
            if ($workspace !== '' && is_dir($workspace) && is_dir($worktreePath)) {
                $this->git($workspace, ['worktree', 'remove', '--force', $worktreePath]);
            }
        } catch (\Throwable) {
            // Abaikan — best effort.
        }
        if (is_dir($worktreePath)) {
            @rmdir($worktreePath);
        }
    }

    /**
     * Git via proc_open tanpa shell (pola sama seperti GithubProjectService).
     */
    protected function git(string $cwd, array $args): string
    {
        $command = array_merge(['git', '-c', 'credential.helper='], $args);
        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptor, $pipes, $cwd, ['GIT_TERMINAL_PROMPT' => '0']);
        if (! is_resource($process)) {
            throw new RuntimeException('Git tidak dapat dijalankan.');
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException('Perintah git demo gagal.');
        }

        return $stdout;
    }

    public function stop(DemoServer $demo, string $status = 'STOPPED'): void
    {
        if ($demo->pid && $this->pidAlive($demo->pid)) {
            @posix_kill($demo->pid, 15); // SIGTERM
            sleep(1);
            if ($this->pidAlive($demo->pid)) {
                @posix_kill($demo->pid, 9); // SIGKILL
            }
        }
        // Bersihkan worktree integration bila ada.
        if (filled($demo->worktree_path)) {
            $project = $demo->project;
            if ($project) {
                $this->removeWorktree($project, (string) $demo->worktree_path);
            }
        }
        $demo->forceFill(['status' => $status])->save();
    }

    /** Hentikan semua demo yang kedaluwarsa/mati. Return jumlah yang dihentikan. */
    public function prune(): int
    {
        $count = 0;
        foreach (DemoServer::query()->where('status', 'RUNNING')->get() as $demo) {
            if ($demo->expires_at?->isPast() || ! $demo->isAlive()) {
                $this->stop($demo, 'EXPIRED');
                $count++;
            }
        }

        return $count;
    }

    public function url(DemoServer $demo, string $host): string
    {
        $host = preg_replace('/:\d+$/', '', $host) ?: $host;

        return 'http://'.$host.':'.$demo->port;
    }

    private function allocatePort(): int
    {
        $from = (int) config('demo.port_from', 9110);
        $to = (int) config('demo.port_to', 9130);
        $used = DemoServer::query()->where('status', 'RUNNING')->pluck('port')->all();
        for ($port = $from; $port <= $to; $port++) {
            if (in_array($port, $used, true) || $this->portOpen($port)) {
                continue;
            }

            return $port;
        }
        throw new RuntimeException('Tidak ada port demo yang tersedia.');
    }

    private function portOpen(int $port): bool
    {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);

        if (is_resource($socket)) {
            fclose($socket);

            return true;
        }

        return false;
    }

    private function pidAlive(?int $pid): bool
    {
        return $pid && function_exists('posix_kill') && @posix_kill($pid, 0);
    }
}
