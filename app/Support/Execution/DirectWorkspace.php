<?php

namespace App\Support\Execution;

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
