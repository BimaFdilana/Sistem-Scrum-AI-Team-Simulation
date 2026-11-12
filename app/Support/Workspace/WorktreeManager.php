<?php

namespace App\Support\Workspace;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
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
            $this->normalizeRepo($baselinePath);

            return;
        }
        $this->git($baselinePath, ['init', '-b', 'main']);
        $this->normalizeRepo($baselinePath);
        $this->git($baselinePath, ['add', '-A']);
        $this->git($baselinePath, [
            '-c', 'user.email=scrumspace@local', '-c', 'user.name=ScrumSpace',
            'commit', '-m', 'Baseline awal project', '--no-verify',
        ]);
    }

    /**
     * Nonaktifkan pelacakan perubahan mode file di repo ini.
     *
     * WAJIB: worker queue berjalan sebagai www-data sedangkan Hermes berjalan
     * sebagai user lain lewat wrapper sudoers. File yang ditulis agent bisa
     * berubah permission-nya (100644 -> 100755) tanpa isinya berubah, dan
     * dengan core.fileMode=true git menandai SETIAP file seperti itu sebagai
     * "modified". Terbukti di baseline project Laundry: 1507 file "berubah",
     * 0 di antaranya berbeda isi — semuanya murni mode change. Akibatnya
     * changedFiles() mengembalikan 1507 path, diff ke QA jadi sampah, dan
     * gerbang "Hermes tidak mengubah file apa pun" salah menilai.
     *
     * Best-effort: kegagalan konfigurasi tidak boleh menggagalkan pembuatan
     * project/worktree — hanya dicatat, karena repo tetap bisa dipakai
     * (hanya lebih berisik).
     */
    public function normalizeRepo(string $path): void
    {
        if (! File::exists($path.'/.git')) {
            return;
        }
        try {
            $this->git($path, ['config', 'core.fileMode', 'false']);
        } catch (RuntimeException $exception) {
            Log::warning('Gagal menonaktifkan core.fileMode', [
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);
        }
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
        // Dua task paralel bisa membuat worktree bersamaan; git memakai
        // index.lock dan salah satunya akan gagal. Kunci per-repo membuat
        // pembuatan worktree berurutan walau task-nya paralel.
        $lockHandle = $this->acquireRepoLock($baselinePath);

        try {
            return $this->createUnlocked($baselinePath, $branch, $fromBranch);
        } finally {
            $this->releaseRepoLock($lockHandle);
        }
    }

    /** @return resource|null */
    private function acquireRepoLock(string $baselinePath)
    {
        $lockFile = rtrim($baselinePath, '/').'/.git/scrumspace-worktree.lock';
        $handle = @fopen($lockFile, 'c');

        if ($handle === false) {
            return null;
        }

        @flock($handle, LOCK_EX);

        return $handle;
    }

    /** @param resource|null $handle */
    private function releaseRepoLock($handle): void
    {
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    private function createUnlocked(string $baselinePath, string $branch, ?string $fromBranch = null): string
    {
        $path = dirname($baselinePath).'/worktrees/'.$branch;
        // Worktree baru mewarisi config dari repo utama untuk sebagian kunci,
        // tapi core.fileMode TIDAK dijamin ikut — dan bila ia kembali true,
        // seluruh masalah 1507-file palsu muncul lagi per task. Jadi
        // normalisasi eksplisit di sini, bukan hanya di ensureRepo.
        $this->normalizeRepo($baselinePath);
        try {
            $this->git($baselinePath, ['worktree', 'prune']);
        } catch (RuntimeException) {
            // Prune best-effort; kegagalan di sini tidak boleh menggagalkan create.
        }
        File::ensureDirectoryExists(dirname($path), 0700);
        if ($this->isWorktreeRegistered($baselinePath, $path)) {
            $this->normalizeRepo($path);

            return $path;
        }
        if ($this->branchExists($baselinePath, $branch)) {
            $this->removePath($path);
            $this->git($baselinePath, ['worktree', 'add', $path, $branch]);
            $this->normalizeRepo($path);
            WorkspacePermissions::grant($path);

            return $path;
        }
        $this->removePath($path);
        $base = ($fromBranch !== null && $this->branchExists($baselinePath, $fromBranch)) ? $fromBranch : 'HEAD';
        $this->git($baselinePath, ['worktree', 'add', '-b', $branch, $path, $base]);
        $this->normalizeRepo($path);
        WorkspacePermissions::grant($path);

        return $path;
    }

    /**
     * Hapus path lama sebelum worktree add, dan PASTIKAN benar-benar hilang.
     *
     * Kasus nyata (run #3, 4 Nov 2026): worktree rusak dimiliki user lain
     * (sidik) sehingga `File::deleteDirectory()` sebagai www-data gagal
     * diam-diam; `git worktree add` lalu gagal dengan pesan membingungkan
     * "path already exists". Lebih baik gagal dengan sebab yang jelas.
     */
    private function removePath(string $path): void
    {
        if (! File::exists($path)) {
            return;
        }

        try {
            File::deleteDirectory($path);
        } catch (\Throwable) {
            // Diverifikasi di bawah; pesannya lebih berguna daripada exception mentah.
        }

        if (File::exists($path)) {
            throw new RuntimeException(
                'Worktree lama di '.$path.' tidak bisa dihapus (kemungkinan dimiliki user lain / sisa proses sebelumnya). '
                .'Bersihkan direktori itu lalu jalankan task lagi.'
            );
        }
    }

    public function branchExists(string $baselinePath, string $branch): bool
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

    /**
     * File yang berubah tetapi BELUM di-commit di worktree.
     *
     * Dipakai jalur OpenCode: agen mengedit working tree lalu keluar, jadi
     * `changedFiles()` (yang membandingkan commit branch) selalu kosong dan
     * task salah dianggap "tanpa perubahan".
     *
     * @return array<int, string>
     */
    public function dirtyFiles(string $worktree): array
    {
        try {
            $output = $this->git($worktree, ['status', '--porcelain']);
        } catch (RuntimeException) {
            return [];
        }

        $files = [];
        foreach (explode("\n", $output) as $line) {
            $line = rtrim($line);
            if (trim($line) === '') {
                continue;
            }

            // Format: " M path" / "?? path" / "R  old -> new"
            $path = trim(substr($line, 3));
            if (str_contains($path, ' -> ')) {
                $path = trim(explode(' -> ', $path)[1]);
            }

            if ($path !== '') {
                $files[] = $path;
            }
        }

        return array_values(array_unique($files));
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

    /** Nama branch yang sedang aktif di sebuah repo. */
    public function currentBranch(string $path): string
    {
        return trim($this->git($path, ['rev-parse', '--abbrev-ref', 'HEAD']));
    }

    /**
     * Kembalikan file tertentu ke versi HEAD (hanya file yang disebut, bukan seluruh repo).
     *
     * @param  array<int, string>  $files
     */
    public function restoreFiles(string $path, array $files): void
    {
        if ($files !== []) {
            $this->git($path, ['checkout', '--', ...$files]);
        }
    }

    /** Apakah $ancestor sudah termasuk dalam riwayat $of (mis. HEAD)? */
    public function isAncestor(string $path, string $ancestor, string $of = 'HEAD'): bool
    {
        try {
            $this->git($path, ['merge-base', '--is-ancestor', $ancestor, $of]);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /** Pindah ke branch yang sudah ada (tanpa force: gagal bila mengorbankan perubahan lokal). */
    public function checkoutBranch(string $path, string $branch): void
    {
        $this->git($path, ['checkout', '--quiet', $branch]);
    }

    /** Buat branch baru dari ref tertentu lalu pindah ke sana. */
    public function createBranchAndCheckout(string $path, string $branch, string $fromRef): void
    {
        $this->git($path, ['checkout', '--quiet', '-b', $branch, $fromRef]);
    }

    /** Commit HEAD saat ini (untuk rollback merge). */
    public function head(string $path): string
    {
        return trim($this->git($path, ['rev-parse', 'HEAD']));
    }

    /** Kembalikan repo ke commit tertentu — dipakai saat gate integrasi gagal. */
    /** Simpan commit di ref cadangan agar tetap bisa dipulihkan setelah reset. */
    public function preserveRef(string $path, string $ref, string $commit): void
    {
        $this->git($path, ['update-ref', $ref, $commit]);
    }

    public function resetHard(string $path, string $commit): void
    {
        $this->git($path, ['reset', '--hard', $commit]);
        $this->normalizeRepo($path);
    }

    /**
     * Daftar file yang berbeda antara dua commit.
     *
     * @return array<int, string>
     */
    public function changedFilesBetween(string $path, string $from, string $to): array
    {
        $output = $this->git($path, ['diff', '--name-only', $from, $to]);
        $files = array_filter(array_map('trim', explode("\n", $output)));
        $files = array_values(array_filter($files, fn (string $file) => $file !== '' && ! str_starts_with($file, '..')));

        return $files;
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
            // Catat file yang bentrok SEBELUM membatalkan: pesan galat git tidak memuat namanya.
            $conflicts = [];
            try {
                $conflicts = array_values(array_filter(array_map('trim', explode("\n", $this->git($baselinePath, ['diff', '--name-only', '--diff-filter=U'])))));
            } catch (RuntimeException) {
                // lanjut membatalkan penggabungan
            }
            $this->git($baselinePath, ['merge', '--abort']);
            throw new RuntimeException('Merge konflik pada '.$branch.($conflicts !== [] ? ' (file: '.implode(', ', $conflicts).')' : '').': '.$exception->getMessage());
        }
    }

    /**
     * Batalkan satu commit dengan commit pembalik (git revert), tanpa menulis
     * ulang riwayat. Commit merge dibalik relatif ke induk pertamanya.
     */
    public function revertCommit(string $path, string $sha): void
    {
        $parents = preg_split('/\s+/', trim($this->git($path, ['rev-list', '--parents', '-n', '1', $sha]))) ?: [];
        $args = ['-c', 'user.email=scrumspace@local', '-c', 'user.name=ScrumSpace', 'revert', '--no-edit'];
        if (count($parents) > 2) {
            $args[] = '-m';
            $args[] = '1';
        }
        $args[] = $sha;
        try {
            $this->git($path, $args);
        } catch (RuntimeException $exception) {
            try {
                $this->git($path, ['revert', '--abort']);
            } catch (RuntimeException) {
                // tidak ada revert yang perlu dibatalkan
            }
            throw $exception;
        }
    }

    /** Hapus worktree + branch task. */
    public function remove(string $baselinePath, string $worktreePath, string $branch): void
    {
        $this->assertNotBaseline($baselinePath, $worktreePath);

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
    /**
     * Jangan pernah menghapus direktori baseline lewat pembersihan worktree.
     *
     * Pada mode `direct`, worktree_path task = baseline. Tanpa penjagaan ini,
     * pembersihan setelah merge/gagal merge MENGHAPUS seluruh project (kasus
     * US-02 run #4).
     */
    private function assertNotBaseline(string $baselinePath, string $worktreePath): void
    {
        $baseline = rtrim(realpath($baselinePath) ?: $baselinePath, '/');
        $target = rtrim(realpath($worktreePath) ?: $worktreePath, '/');

        if ($target === $baseline) {
            throw new RuntimeException('Menolak menghapus baseline project sebagai worktree.');
        }
    }

    /**
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
