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

    /**
     * Buat worktree + branch baru dari HEAD baseline. Return path worktree.
     *
     * Revisi eksekusi US-01 #6 (15:00:25 UTC) gagal di sini: pengerjaan
     * ulang task memakai branch 'exec-{run}-task-{id}' yang SAMA, tapi
     * create() selalu `worktree add -b`, sehingga git menolak dengan
     * "a branch named ... already exists" — sebelum agent dipanggil —
     * dan catch failRun menandai run EXECUTION_FAILED. Jadi:
     *  - bila worktree cabang itu sudah terdaftar di path ini, pakai
     *    kembali apa adanya (pengerjaan revisi melanjutkan cabang task);
     *  - bila cabang ada tapi worktree-nya tidak terdaftar (mis. direktori
     *    terhapus), daftarkan ulang TANPA -b setelah `worktree prune`
     *    membersihkan registrasi yatim;
     *  - bila cabang belum ada, perilaku lama (`add -b`).
     */
    /**
     * Buat worktree + branch baru. Bila $fromBranch diberikan dan ada,
     * cabang baru dibuat dari cabang itu (pengerjaan revisi mewarisi
     * file percobaan sebelumnya); bila tidak, dari HEAD baseline.
     * Nama cabang per task+attempt (wt-{run}-{task}-{attempt}) membuat
     * tiap percobaan punya worktree sendiri — menggantikan pola lama
     * satu cabang per task yang menabrak di revisi (#6).
     */
    public function create(string $baselinePath, string $branch, ?string $fromBranch = null): string
    {
        $path = dirname($baselinePath).'/worktrees/'.$branch;
        try {
            $this->git($baselinePath, ['worktree', 'prune']);
        } catch (RuntimeException) {
            // Prune best-effort; kegagalan di sini tidak boleh menggagalkan create.
        }
        File::ensureDirectoryExists(dirname($path), 0700);
        if ($this->isWorktreeRegistered($baselinePath, $path)) {
            return $path;
        }
        if ($this->branchExists($baselinePath, $branch)) {
            if (File::exists($path)) {
                File::deleteDirectory($path);
            }
            $this->git($baselinePath, ['worktree', 'add', $path, $branch]);

            return $path;
        }
        if (File::exists($path)) {
            File::deleteDirectory($path);
        }
        $base = ($fromBranch !== null && $this->branchExists($baselinePath, $fromBranch)) ? $fromBranch : 'HEAD';
        $this->git($baselinePath, ['worktree', 'add', '-b', $branch, $path, $base]);

        return $path;
    }

    private function branchExists(string $baselinePath, string $branch): bool
    {
        try {
            $this->git($baselinePath, ['show-ref', '--verify', '--quiet', 'refs/heads/'.$branch]);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function isWorktreeRegistered(string $baselinePath, string $path): bool
    {
        try {
            $list = $this->git($baselinePath, ['worktree', 'list', '--porcelain']);
        } catch (RuntimeException) {
            return false;
        }
        foreach (explode("\n", $list) as $line) {
            if (str_starts_with($line, 'worktree ') && trim(substr($line, 9)) === $path) {
                return true;
            }
        }

        return false;
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

    /** Daftar file yang berubah antara basis dan branch task. */
    public function changedFiles(string $baselinePath, ?string $branch, string $base = 'HEAD'): array
    {
        if ($branch === null || $branch === '') {
            return [];
        }
        try {
            $output = $this->git($baselinePath, ['diff', '--name-only', $base, $branch, '--']);
        } catch (RuntimeException) {
            return [];
        }
        $files = array_filter(array_map('trim', explode("\n", $output)));

        return array_values($files);
    }

    /**
     * Diff statistik + isi (dibatasi) antara basis dan branch task.
     * $base default HEAD (perilaku lama); untuk task berantai, pemanggil
     * mengisi chain base-nya agar diff QA tetap per task, bukan kumulatif.
     */
    public function diff(string $baselinePath, string $branch, string $base = 'HEAD', int $maxBytes = 20000): string
    {
        $stat = $this->git($baselinePath, ['diff', '--stat', $base, $branch, '--']);
        $body = $this->git($baselinePath, ['diff', $base, $branch, '--']);
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

    /**
     * Hapus SEMUA worktree + branch percobaan satu task (semua attempt
     * wt-{run}-{task}-*, plus nama lama exec-{run}-task-{id} bila ada).
     * Dipakai failRun agar task yang gagal permanen tidak meninggalkan
     * direktori yatim dari percobaan mana pun.
     */
    public function cleanupTaskWorktrees(string $baselinePath, int $runId, int $taskId): void
    {
        $dir = dirname($baselinePath).'/worktrees';
        $prefix = 'wt-'.$runId.'-'.$taskId.'-';
        $legacy = 'exec-'.$runId.'-task-'.$taskId;
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (str_starts_with($entry, $prefix) || $entry === $legacy) {
                $this->remove($baselinePath, $dir.'/'.$entry, $entry);
            }
        }
        try {
            $this->git($baselinePath, ['worktree', 'prune']);
        } catch (RuntimeException) {
            // Best-effort.
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
