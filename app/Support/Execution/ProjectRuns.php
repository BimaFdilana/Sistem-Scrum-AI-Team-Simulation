<?php

namespace App\Support\Execution;

use App\Models\ExecutionRun;
use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * Semua eksekusi satu project (story backlog dan perintah chat), dikelompokkan
 * untuk halaman Eksekusi: perlu keputusan PO, berjalan, gagal, selesai, riwayat.
 */
class ProjectRuns
{
    public const GROUPS = [
        'decision' => 'Perlu keputusan',
        'running' => 'Berjalan',
        'failed' => 'Gagal',
        'done' => 'Selesai',
        'history' => 'Riwayat',
    ];

    /** @return Collection<int, array{run: ExecutionRun, group: string}> */
    public static function for(Project $project): Collection
    {
        $runs = ExecutionRun::query()
            ->with('story')
            ->whereHas('story', fn ($q) => $q->where('project_id', $project->id))
            ->latest('id')
            ->get();
        $latestPerStory = $runs->groupBy('story_id')->map(fn ($group) => $group->max('id'));

        return $runs->map(fn (ExecutionRun $run) => [
            'run' => $run,
            'group' => self::group($run, (int) ($latestPerStory[$run->story_id] ?? 0)),
        ]);
    }

    public static function decisionCount(Project $project): int
    {
        return self::for($project)->where('group', 'decision')->count();
    }

    private static function group(ExecutionRun $run, int $latestId): string
    {
        $story = $run->story;
        $isLatest = $run->id === $latestId;

        if ($story?->acceptance_status !== null && (int) $story->accepted_execution_run_id === (int) $run->id) {
            return 'done';
        }
        if (in_array($run->status, [ExecutionRun::NEEDS_PO, 'AWAITING_PERMISSION'], true)) {
            return 'decision';
        }
        if (in_array($run->status, DirectWorkspace::ACTIVE, true)) {
            return 'running';
        }
        if ($isLatest && $story?->acceptance_status === null) {
            return $run->status === 'COMPLETED' ? 'decision' : 'failed';
        }

        return 'history';
    }
}
