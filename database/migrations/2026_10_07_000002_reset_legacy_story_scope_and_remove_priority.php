<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table): void {
            $table->dropIndex('stories_project_id_scope_status_priority_index');
            $table->dropIndex('stories_sprint_id_priority_index');
            $table->dropColumn('priority');
        });

        DB::table('stories')->update([
            'estimate_points' => null,
            'actor_id' => null,
            'sprint_purpose' => null,
            'sprint_story_status' => 'NOT_STARTED',
            'scope_status' => 'NEEDS_REFINEMENT',
            'scope_approved_revision' => null,
            'scope_approved_by' => null,
            'scope_approved_at' => null,
        ]);

        DB::table('sprints')->update([
            'status' => 'PLANNING',
            'revision' => DB::raw('revision + 1'),
            'scope_approved_revision' => null,
            'scope_approved_by' => null,
            'scope_approved_at' => null,
            'started_at' => null,
            'completed_at' => null,
        ]);
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table): void {
            $table->unsignedTinyInteger('priority')->default(3);
            $table->index(['project_id', 'scope_status', 'priority']);
            $table->index(['sprint_id', 'priority']);
        });
    }
};
