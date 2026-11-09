<?php

namespace App\Support\Demo;

use App\Models\Project;
use App\Models\Story;
use App\Support\Workspace\WorktreeManager;
use Throwable;

/**
 * Isi demo: story yang sudah diterima PO harus ada di kode yang disajikan demo.
 * Demo membaca folder baseline project, jadi yang tampil adalah cabang yang sedang aktif di sana.
 * Daftar ini memperlihatkan story diterima mana yang benar-benar ikut, dan mana yang belum
 * (mis. penerimaan yang gagal digabung karena konflik), supaya celahnya terlihat, bukan ditebak.
 */
class DemoContents
{
    /**
     * @return array{
     *   branch: ?string,
     *   accepted: array<int, array{label: string, branch: ?string, included: bool}>,
     *   pending: array<int, array{label: string, branch: string, shown: bool}>
     * }
     */
    public static function for(Project $project): array
    {
        $empty = ['branch' => null, 'accepted' => [], 'pending' => []];
        $path = rtrim((string) $project->workspace_path, '/');
        if ($path === '' || ! is_dir($path)) {
            return $empty;
        }

        $git = app(WorktreeManager::class);

        try {
            $head = $git->currentBranch($path);
        } catch (Throwable) {
            return $empty;
        }

        $contains = function (string $branch) use ($git, $path): bool {
            try {
                return $git->branchExists($path, $branch) && $git->isAncestor($path, $branch, 'HEAD');
            } catch (Throwable) {
                return false;
            }
        };

        $accepted = [];
        $pending = [];
        $stories = Story::query()->where('project_id', $project->id)->with('executionRuns')->orderBy('id')->get();

        foreach ($stories as $story) {
            $label = $story->display_id.' '.$story->effectiveTitle();
            $run = $story->executionRuns->where('status', 'COMPLETED')->sortBy('id')->last();
            $branch = $run?->work_branch;

            if ($story->acceptance_status === 'BERHASIL') {
                $accepted[] = [
                    'label' => $label,
                    'branch' => $branch,
                    // Tanpa cabang run = era commit langsung ke main: kodenya sudah ada di main.
                    'included' => $branch === null ? true : $contains($branch),
                ];
            } elseif ($story->acceptance_status === null && $run !== null && $branch !== null) {
                $pending[] = ['label' => $label, 'branch' => $branch, 'shown' => $contains($branch)];
            }
        }

        return ['branch' => $head, 'accepted' => $accepted, 'pending' => $pending];
    }
}
