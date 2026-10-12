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
        $docroot = rtrim((string) $project->workspace_path, '/').'/public';
        if (! is_dir($docroot)) {
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
            throw new RuntimeException('Gagal menjalankan server demo.');
        }
        sleep(1);
        if (! $this->pidAlive($pid)) {
            throw new RuntimeException('Server demo langsung berhenti. Periksa log: '.$log);
        }

        return DemoServer::query()->create([
            'project_id' => $project->id,
            'port' => $port,
            'pid' => $pid,
            'status' => 'RUNNING',
            'started_at' => now(),
            'expires_at' => now()->addMinutes((int) config('demo.ttl_minutes', 60)),
        ]);
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
