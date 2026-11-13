<?php

namespace App\Support\Demo;

use App\Models\ExecutionRun;
use App\Models\Project;
use App\Models\Story;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Isi demo: story yang sudah diterima PO harus ada di kode yang disajikan demo.
 * Demo menyajikan folder kodenya sendiri (lihat DemoServerManager::syncDemoTree).
 *
 * Cek "sudah ada" tidak lagi bergantung pada cabang scrum/run-N: cabang yang sudah
 * tergabung boleh dihapus, sementara jejaknya tetap ada sebagai commit
 * "Merge scrum/run-N" di riwayat kode. Dulu penghapusan cabang membuat panel ini
 * keliru melaporkan US-01..06 "belum ada di demo".
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
        $baseline = rtrim((string) $project->workspace_path, '/');
        if ($baseline === '' || ! is_dir($baseline)) {
            return $empty;
        }

        // Folder kode demo (sama dengan DemoServerManager::demoTreePath).
        $tree = dirname($baseline).'/demo';
        $root = file_exists($tree.'/.git') ? $tree : $baseline;

        try {
            $label = $root === $tree
                ? app(DemoServerManager::class)->demoTarget($project)['label']
                : trim(self::git($baseline, ['branch', '--show-current']));
            $merged = array_flip(array_filter(array_map('trim', explode("\n", self::git($root, ['log', '--merges', '--format=%s', 'HEAD'])))));
            $head = trim(self::git($root, ['rev-parse', 'HEAD']));
        } catch (Throwable) {
            return $empty;
        }

        $contains = function (string $branch) use ($baseline, $head, $merged): bool {
            if (isset($merged['Merge '.$branch])) {
                return true;
            }
            try {
                $tip = trim(self::git($baseline, ['rev-parse', '--verify', '--quiet', 'refs/heads/'.$branch]));
                if ($tip === '') {
                    return false;
                }
                self::git($baseline, ['merge-base', '--is-ancestor', $tip, $head]);

                return true;
            } catch (Throwable) {
                return false;
            }
        };

        $accepted = [];
        $pending = [];
        $stories = Story::query()->where('project_id', $project->id)->with('executionRuns')->orderBy('id')->get();

        foreach ($stories as $story) {
            $text = $story->display_id.' '.$story->effectiveTitle();
            $run = $story->accepted_execution_run_id
                ? $story->executionRuns->firstWhere('id', $story->accepted_execution_run_id)
                : $story->executionRuns->where('status', 'COMPLETED')->sortBy('id')->last();
            $run ??= $story->executionRuns->where('status', 'COMPLETED')->sortBy('id')->last();
            $branch = $run instanceof ExecutionRun ? $run->work_branch : null;

            if ($story->acceptance_status === 'BERHASIL') {
                $accepted[] = [
                    'label' => $text,
                    'branch' => $branch,
                    // Tanpa cabang run = era commit langsung ke main: kodenya sudah ada di main.
                    'included' => $branch === null ? true : $contains($branch),
                ];
            } elseif ($story->acceptance_status === null && $run !== null && $branch !== null && $run->status === 'COMPLETED') {
                $pending[] = ['label' => $text, 'branch' => $branch, 'shown' => $contains($branch)];
            }
        }

        return ['branch' => $label !== '' ? $label : null, 'accepted' => $accepted, 'pending' => $pending];
    }

    /** @param  array<int, string>  $args */
    private static function git(string $cwd, array $args): string
    {
        $process = new Process(['git', '-c', 'credential.helper=', ...$args], $cwd, ['GIT_TERMINAL_PROMPT' => '0']);
        $process->setTimeout(20);
        $process->mustRun();

        return $process->getOutput();
    }
}
