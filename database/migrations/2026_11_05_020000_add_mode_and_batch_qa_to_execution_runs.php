<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mode eksekusi per run dan status QA batch.
 *
 * direct   = agent mengedit baseline langsung, sekuensial, commit sebagai
 *            checkpoint, tanpa worktree dan tanpa merge.
 * worktree = perilaku lama (salinan terpisah + merge).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('execution_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('execution_runs', 'mode')) {
                $table->string('mode', 20)->default('worktree')->after('executor_policy');
            }
            if (! Schema::hasColumn('execution_runs', 'qa_mode')) {
                $table->string('qa_mode', 20)->default('per_task')->after('mode');
            }
            if (! Schema::hasColumn('execution_runs', 'qa_rounds')) {
                $table->unsignedTinyInteger('qa_rounds')->default(0)->after('qa_mode');
            }
            if (! Schema::hasColumn('execution_runs', 'qa_started_at')) {
                $table->timestamp('qa_started_at')->nullable()->after('qa_rounds');
            }
            if (! Schema::hasColumn('execution_runs', 'qa_finished_at')) {
                $table->timestamp('qa_finished_at')->nullable()->after('qa_started_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('execution_runs', function (Blueprint $table) {
            foreach (['qa_finished_at', 'qa_started_at', 'qa_rounds', 'qa_mode', 'mode'] as $column) {
                if (Schema::hasColumn('execution_runs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
