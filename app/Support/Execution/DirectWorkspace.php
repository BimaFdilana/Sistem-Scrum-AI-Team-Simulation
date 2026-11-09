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

    /** Pastikan baseline bersih; kalau tidak, jangan menimpa pekerjaan siapa pun. */
    public function assertClean(Project $project): void
    {
        $path = $this->path($project);
        $dirty = $this->git->dirtyFiles($path);

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
