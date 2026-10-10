<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('story_estimation_agent_runs')
            && ! Schema::hasColumn('story_estimation_agent_runs', 'heartbeat_at')) {
            Schema::table('story_estimation_agent_runs', function (Blueprint $table): void {
                $table->timestamp('heartbeat_at')->nullable()->index();
            });
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite'
            && Schema::hasTable('story_estimation_runs')) {
            DB::statement('DROP INDEX IF EXISTS story_estimation_active_revision_unique');
            DB::statement("CREATE UNIQUE INDEX story_estimation_active_revision_unique
                ON story_estimation_runs (story_id, story_revision)
                WHERE status IN ('QUEUED', 'RUNNING', 'REPORT_PENDING', 'COMPLETED')");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite'
            && Schema::hasTable('story_estimation_runs')) {
            DB::statement('DROP INDEX IF EXISTS story_estimation_active_revision_unique');
            DB::statement("CREATE UNIQUE INDEX story_estimation_active_revision_unique
                ON story_estimation_runs (story_id, story_revision)
                WHERE status IN ('RUNNING', 'REPORT_PENDING', 'COMPLETED')");
        }

        if (Schema::hasTable('story_estimation_agent_runs')
            && Schema::hasColumn('story_estimation_agent_runs', 'heartbeat_at')) {
            Schema::table('story_estimation_agent_runs', function (Blueprint $table): void {
                $table->dropColumn('heartbeat_at');
            });
        }
    }
};
