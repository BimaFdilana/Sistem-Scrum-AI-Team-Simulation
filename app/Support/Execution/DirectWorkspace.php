<?php

namespace App\Support\Execution;

use App\Models\ExecutionRun;
use App\Models\Project;
use App\Support\Workspace\WorktreeManager;
use RuntimeException;

/**
 * Pekerjaan langsung di baseline: tanpa worktree, tanpa merge.
 *
 * Dipakai mode `direct`. Jaring pengamannya adalah commit checkpoint per task:
 * sebelum task mulai dicatat HEAD, dan bila task gagal total repo dikembalikan
 * ke commit itu. Jadi tidak ada file setengah jadi yang tertinggal.
 *
 * Sengaja TIDAK ada rollback `reset --hard` / `clean -fd` di baseline utama:
 * perintah itu bisa menghapus pekerjaan yang belum di-commit. Pemulihan
 * dilakukan manual dari commit checkpoint, bukan otomatis.
 */
class DirectWorkspace
{
    public function __construct(private readonly WorktreeManager $git) {}

    /** Status run yang masih memakai baseline; sisa perubahannya tidak boleh disentuh. */
    public const ACTIVE = ['QUEUED', 'BREAKDOWN', 'AWAITING_PERMISSION', 'EXECUTING', 'REVIEWING', 'QA_REVIEW', 'MERGING'];

    /**
     * Pulihkan baseline yang kotor TANPA menghapus apa pun.
     *
     * Penyebab nyata (Sprint 2, US-37): task OpenCode timeout di tengah jalan
     * sehingga 2 file setengah jadi tertinggal. Setiap eksekusi berikutnya lalu
     * gagal "Baseline project tidak bersih" tanpa jalan keluar bagi PO.
     *
     * Kini sisa perubahan di-commit ke cabangnya sendiri (cabang run yang gagal,
     * atau cabang penyimpanan scrumspace/sisa-*), lalu baseline kembali ke
     * cabang basis. Tidak ada reset/clean: pekerjaan tetap bisa dipulihkan.
     *
     * @return string|null catatan untuk PO, atau null bila baseline sudah bersih
     */
    public function recoverLeftovers(Project $project, ?ExecutionRun $current = null): ?string
    {
        $path = $this->path($project);
        $this->restoreLockfileNoise($path);
        $dirty = $this->git->dirtyFiles($path);
        if ($dirty === []) {
            return null;
        }

        $branch = $this->git->currentBranch($path);
        $list = implode(', ', array_slice($dirty, 0, 5)).(count($dirty) > 5 ? ', ...' : '');
        $owner = str_starts_with($branch, 'scrum/run-')
            ? ExecutionRun::query()->where('work_branch', $branch)->first()
            : null;

        if ($owner !== null && $current !== null && (int) $owner->id === (int) $current->id) {
            // Sisa percobaan sebelumnya dari run yang sama: simpan sebagai checkpoint.
            $this->git->commit($path, 'wip(run-'.$owner->id.'): sisa percobaan task sebelumnya');

            return null;
        }
        if ($owner !== null && in_array($owner->status, self::ACTIVE, true)) {
            throw new RuntimeException('Eksekusi #'.$owner->id.' masih memakai baseline project. Tunggu sampai selesai, lalu coba lagi.');
        }
        if ($owner !== null) {
            $this->git->commit($path, 'wip(run-'.$owner->id.'): sisa pekerjaan yang tidak selesai');
            $base = $owner->base_branch ?: 'main';
            $this->git->checkoutBranch($path, $base);

            return count($dirty).' file sisa eksekusi #'.$owner->id.' ('.$list.') disimpan di cabang '.$branch.'; baseline kembali bersih di '.$base.'.';
        }

        $rescue = 'scrumspace/sisa-'.now()->format('Ymd-His');
        $this->git->createBranchAndCheckout($path, $rescue, 'HEAD');
        $this->git->commit($path, 'Sisa perubahan yang belum di-commit, dipindahkan otomatis oleh ScrumSpace');
        $this->git->checkoutBranch($path, $branch !== '' && $branch !== 'HEAD' ? $branch : 'main');

        return count($dirty).' file yang belum di-commit ('.$list.') dipindahkan ke cabang '.$rescue.' agar baseline bersih.';
    }

    /**
     * Run gagal: simpan pekerjaan setengah jadi di cabang run itu dan kembalikan
     * baseline ke cabang basis, supaya eksekusi berikutnya tidak tertahan.
     */
    public function releaseAfterFailure(Project $project, ExecutionRun $run): ?string
    {
        if (! $run->work_branch) {
            return null;
        }
        $path = $this->path($project);
        if ($this->git->currentBranch($path) !== $run->work_branch) {
            return null;
        }
        $this->restoreLockfileNoise($path);
        $dirty = $this->git->dirtyFiles($path);
        if ($dirty !== []) {
            $this->git->commit($path, 'wip(run-'.$run->id.'): pekerjaan setengah jadi saat eksekusi gagal');
        }
        $base = $run->base_branch ?: 'main';
        $this->git->checkoutBranch($path, $base);

        return $dirty !== []
            ? count($dirty).' file setengah jadi disimpan di cabang '.$run->work_branch.'; baseline kembali ke '.$base.'.'
            : 'Baseline kembali ke '.$base.'.';
    }

    private function restoreLockfileNoise(string $path): void
    {
        $dirty = $this->git->dirtyFiles($path);
        $lockfiles = ['package-lock.json', 'npm-shrinkwrap.json', 'yarn.lock', 'pnpm-lock.yaml'];
        $noise = array_values(array_intersect($dirty, $lockfiles));
        if ($noise !== []) {
            $this->git->restoreFiles($path, $noise);
        }
    }

    /** Pastikan baseline bersih; kalau tidak, jangan menimpa pekerjaan siapa pun. */
    public function assertClean(Project $project): void
    {
        $path = $this->path($project);
        $dirty = $this->git->dirtyFiles($path);

        // Lockfile yang ditulis ulang alat (npm/yarn/pnpm) adalah noise tooling, bukan
        // pekerjaan siapa pun. Bila SEMUA yang kotor hanya lockfile, pulihkan; bila ada
        // perubahan lain, tetap tolak dan jangan sentuh apa pun.
        $lockfiles = ['package-lock.json', 'npm-shrinkwrap.json', 'yarn.lock', 'pnpm-lock.yaml'];
        if ($dirty !== [] && array_diff($dirty, $lockfiles) === []) {
            $this->git->restoreFiles($path, $dirty);
            $dirty = $this->git->dirtyFiles($path);
        }

        if ($dirty !== []) {
            throw new RuntimeException(
                'Baseline project tidak bersih ('.count($dirty).' file belum di-commit: '
                .implode(', ', array_slice($dirty, 0, 5)).'). Mode langsung menolak menimpa perubahan yang ada.'
            );
        }
    }

    /**
     * Mulai (atau lanjutkan) pekerjaan run di branch sendiri `scrum/run-{id}`.
     *
     * Baseline utama tidak disentuh sampai PO menerima hasilnya. Titik awal
     * disimpan di `refs/scrumspace/run-{id}-start` sebagai snapshot yang bisa
     * dipulihkan. Idempotent: dipanggil tiap task.
     */
    public function beginRun(Project $project, ExecutionRun $run): void
    {
        $path = $this->path($project);
        $work = $run->work_branch ?: 'scrum/run-'.$run->id;

        if ($this->git->branchExists($path, $work)) {
            if ($this->git->currentBranch($path) !== $work) {
                $this->git->checkoutBranch($path, $work);
            }
            if (! $run->work_branch) {
                $run->forceFill(['work_branch' => $work])->save();
            }

            return;
        }

        $current = $this->git->currentBranch($path);
        $base = $current;
        if (str_starts_with($current, 'scrum/run-')) {
            // Sedang di branch run lain yang belum diputuskan: basisnya tetap branch utama.
            $previous = ExecutionRun::query()->where('work_branch', $current)->value('base_branch');
            $base = $previous ?: 'main';
            $this->git->checkoutBranch($path, $base);
        }

        $this->git->preserveRef($path, 'refs/scrumspace/run-'.$run->id.'-start', $this->git->head($path));
        $this->git->createBranchAndCheckout($path, $work, $base);
        $run->forceFill(['base_branch' => $base, 'work_branch' => $work])->save();
    }

    /**
     * Akhiri run di baseline: kembali ke branch basis; bila diterima, gabungkan hasilnya.
     * Run yang ditolak tidak menyentuh branch basis; hasilnya tetap di branch run.
     *
     * @return string|null pesan galat (Bahasa Indonesia) atau null bila sukses/tidak berlaku
     */
    public function settle(Project $project, ExecutionRun $run, bool $accepted): ?string
    {
        if (! $run->work_branch || ! $run->base_branch) {
            return null;
        }

        try {
            $path = $this->path($project);
            if ($this->git->currentBranch($path) !== $run->base_branch) {
                $this->git->checkoutBranch($path, $run->base_branch);
            }
            if ($accepted) {
                $this->git->merge($path, $run->work_branch);
            }
        } catch (RuntimeException $exception) {
            if (str_contains(mb_strtolower($exception->getMessage()), 'konflik')) {
                preg_match('/\(file: ([^)]*)\)/', $exception->getMessage(), $matches);
                $files = isset($matches[1]) && $matches[1] !== '' ? explode(', ', $matches[1]) : [];

                return 'Story belum bisa diterima: hasilnya bentrok dengan kode yang sudah diterima'
                    .($files !== [] ? ' (file: '.implode(', ', $files).')' : '')
                    .'. Selesaikan konflik itu di cabang '.$run->work_branch.' lalu terima lagi; story tidak ditandai Berhasil.';
            }

            return 'Baseline tidak bisa dikembalikan ke branch utama: '.mb_substr($exception->getMessage(), 0, 300);
        }

        return null;
    }

    public function head(Project $project): string
    {
        return $this->git->head($this->path($project));
    }

    /** Commit checkpoint untuk satu task. */
    public function checkpoint(Project $project, string $message): string
    {
        $path = $this->path($project);
        $this->git->commit($path, $message);

        return $this->git->head($path);
    }

    /** Diff gabungan seluruh run (untuk review QA batch). */
    public function combinedDiff(Project $project, string $fromCommit, int $maxBytes = 40000): string
    {
        $path = $this->path($project);

        return $this->git->diff($path, 'HEAD', $fromCommit, $maxBytes);
    }

    /** @return array<int, string> */
    public function changedFiles(Project $project, string $fromCommit): array
    {
        $path = $this->path($project);

        return $this->git->changedFilesBetween($path, $fromCommit, 'HEAD');
    }

    private function path(Project $project): string
    {
        $path = rtrim((string) $project->workspace_path, '/');
        if ($path === '' || ! is_dir($path)) {
            throw new RuntimeException('Baseline project tidak ditemukan untuk mode langsung.');
        }

        return $path;
    }
}
