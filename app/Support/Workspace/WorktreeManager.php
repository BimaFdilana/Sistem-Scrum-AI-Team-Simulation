<?php

namespace App\Support\Workspace;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Mengelola git worktree terisolasi per task eksekusi.
 * Baseline harus repository git (dipastikan via ensureRepo).
 */
class WorktreeManager
{
    private const COMMAND_TIMEOUT = 120;

    /** Pastikan baseline adalah repo git; bila belum, init + commit awal. */
    public function ensureRepo(string $baselinePath): void
    {
        if (File::exists($baselinePath.'/.git')) {
            return;
        }
        $this->git($baselinePath, ['init', '-b', 'main']);
        $this->git($baselinePath, ['add', '-A']);
        $this->git($baselinePath, [
            '-c', 'user.email=scrumspace@local', '-c', 'user.name=ScrumSpace',
            'commit', '-m', 'Baseline awal project', '--no-verify',
        ]);
    }

    /** Buat worktree + branch baru dari HEAD baseline. Return path worktree. */
    public function create(string $baselinePath, string $branch): string
    {
        $path = dirname($baselinePath).'/worktrees/'.$branch;
        if (File::exists($path)) {
            File::deleteDirectory($path);
        }
        File::ensureDirectoryExists(dirname($path), 0700);
        $this->git($baselinePath, ['worktree', 'add', '-b', $branch, $path, 'HEAD']);

        return $path;
    }

    public function commit(string $worktreePath, string $message): void
    {
        $this->git($worktreePath, ['add', '-A']);
        $status = trim($this->git($worktreePath, ['status', '--porcelain']));
        if ($status === '') {
            return; // tidak ada perubahan
        }
        $this->git($worktreePath, [
            '-c', 'user.email=scrumspace@local', '-c', 'user.name=ScrumSpace',
            'commit', '-m', $message, '--no-verify',
        ]);
    }

    /** Diff statistik + isi (dibatasi) antara baseline HEAD dan branch task. */
    public function diff(string $baselinePath, string $branch, int $maxBytes = 20000): string
    {
        $stat = $this->git($baselinePath, ['diff', '--stat', 'HEAD', $branch, '--']);
        $body = $this->git($baselinePath, ['diff', 'HEAD', $branch, '--']);
        if (strlen($body) > $maxBytes) {
            $body = substr($body, 0, $maxBytes)."\n... (diff dipotong)";
        }

        return $stat."\n\n".$body;
    }

    /** Merge branch task ke baseline. Throw bila konflik. */
    public function merge(string $baselinePath, string $branch): void
    {
        // Pastikan di branch utama baseline sebelum merge.
        $current = trim($this->git($baselinePath, ['branch', '--show-current']));
        if ($current === '') {
            $current = 'main';
        }
        try {
            $this->git($baselinePath, [
                '-c', 'user.email=scrumspace@local', '-c', 'user.name=ScrumSpace',
                'merge', '--no-ff', '--no-verify', '-m', 'Merge '.$branch, $branch,
            ]);
        } catch (RuntimeException $exception) {
            $this->git($baselinePath, ['merge', '--abort']);
            throw new RuntimeException('Merge konflik pada '.$branch.': '.$exception->getMessage());
        }
    }

    /** Hapus worktree + branch task. */
    public function remove(string $baselinePath, string $worktreePath, string $branch): void
    {
        try {
            $this->git($baselinePath, ['worktree', 'remove', '--force', $worktreePath]);
        } catch (RuntimeException) {
            // Worktree mungkin sudah hilang; lanjutkan.
        }
        try {
            $this->git($baselinePath, ['branch', '-D', $branch]);
        } catch (RuntimeException) {
            // Branch mungkin sudah hilang; abaikan.
        }
        if (File::exists($worktreePath)) {
            File::deleteDirectory($worktreePath);
        }
    }

    private function git(string $cwd, array $args): string
    {
        $command = array_merge(['git', '-c', 'credential.helper='], $args);
        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = [
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
        ];
        foreach (['http_proxy', 'https_proxy', 'HTTP_PROXY', 'HTTPS_PROXY', 'no_proxy', 'NO_PROXY'] as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $env[$key] = $value;
            }
        }
        $process = proc_open($command, $descriptor, $pipes, $cwd, $env);
        if (! is_resource($process)) {
            throw new RuntimeException('Git tidak dapat dijalankan.');
        }
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $started = microtime(true);
        $stdout = '';
        $stderr = '';
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (! $status['running']) {
                break;
            }
            if (microtime(true) - $started > self::COMMAND_TIMEOUT) {
                proc_terminate($process, 9);
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }
                proc_close($process);
                throw new RuntimeException('Perintah git melebihi batas waktu: git '.implode(' ', $args));
            }
            usleep(100000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('Git gagal ('.implode(' ', $args).'): '.mb_substr(trim($stderr), -300));
        }

        return $stdout;
    }
}
