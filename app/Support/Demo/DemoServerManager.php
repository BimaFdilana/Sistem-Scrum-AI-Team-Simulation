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

        $port = $this->allocatePort();
        $log = storage_path('logs/demo-project-'.$project->id.'.log');
        $cmd = sprintf(
            'nohup php -S 0.0.0.0:%d -t %s > %s 2>&1 & echo $!',
            $port, escapeshellarg($docroot), escapeshellarg($log)
        );
        $pid = (int) trim((string) shell_exec($cmd));
        if ($pid <= 0) {
            if ($worktreePath !== null) {
                $this->removeWorktree($project, $worktreePath);
            }
            throw new RuntimeException('Gagal menjalankan server demo.');
        }
        sleep(1);
        if (! $this->pidAlive($pid)) {
            if ($worktreePath !== null) {
                $this->removeWorktree($project, $worktreePath);
            }
            throw new RuntimeException('Server demo langsung berhenti. Periksa log: '.$log);
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
