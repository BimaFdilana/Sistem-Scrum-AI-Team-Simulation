<?php

namespace App\Support\Projects;

use App\Models\Actor;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data papan Sprint Backlog yang ditampilkan di halaman Project.
 */
class ProjectBoard
{
    private const SPRINT_MIGRATION = '2026_10_08_000001_make_sprint_goal_and_legacy_dates_optional';

    /** Skema backlog/sprint/story sudah dimigrasi? Bila belum, papan tidak bisa ditampilkan. */
    public static function ready(): bool
    {
        return Schema::hasTable('backlogs')
            && Schema::hasColumn('sprints', 'backlog_id')
            && Schema::hasColumn('stories', 'user_story_key');
    }

    /** @return array<string, mixed> */
    public static function for(Project $project): array
    {
        $estimationSchemaReady = Schema::hasTable('story_estimation_runs')
            && Schema::hasTable('story_estimation_agent_runs')
            && Schema::hasTable('story_estimation_messages');
        $relations = ['stories.actor'];
        if ($estimationSchemaReady) {
            $relations[] = 'stories.estimationRuns.agentRuns';
        }
        if (Schema::hasTable('execution_runs')) {
            // tasks ikut dimuat agar status eksekusi tampil tanpa query tambahan per story.
            $relations[] = 'stories.executionRuns.tasks';
        }

        $backlog = $project->backlog;
        $sprints = $backlog->sprints()->withCount('stories')->with($relations)->latest()->get();
        $stories = $sprints->flatMap->stories;

        return [
            'backlog' => $backlog,
            'sprints' => $sprints,
            'actors' => Actor::query()->where('is_active', true)->orderBy('name')->get(),
            'sprintSchemaReady' => DB::table('migrations')->where('migration', self::SPRINT_MIGRATION)->exists(),
            'estimationSchemaReady' => $estimationSchemaReady,
            'stats' => [
                'sprints' => $sprints->count(),
                'stories' => $stories->count(),
                'accepted' => $stories->filter->isAccepted()->count(),
                'points' => (int) $stories->sum('estimate_points'),
            ],
        ];
    }
}
