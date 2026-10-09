<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('story_estimation_runs')) {
            return;
        }

        if (! Schema::hasColumn('story_estimation_runs', 'story_snapshot_hash')) {
            Schema::table('story_estimation_runs', function (Blueprint $table): void {
                $table->string('story_snapshot_hash', 64)->nullable();
            });
        }
        if (! Schema::hasColumn('story_estimation_runs', 'consensus_points')) {
            Schema::table('story_estimation_runs', function (Blueprint $table): void {
                $table->unsignedTinyInteger('consensus_points')->nullable();
            });
        }
        if (! Schema::hasColumn('story_estimation_runs', 'report_sent_at')) {
            Schema::table('story_estimation_runs', function (Blueprint $table): void {
                $table->timestamp('report_sent_at')->nullable();
            });
        }
        if (! Schema::hasTable('story_estimation_messages')) {
            Schema::create('story_estimation_messages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('story_estimation_run_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('story_revision');
                $table->unsignedTinyInteger('round');
                $table->string('sender_slug', 80);
                $table->string('recipient_slug', 80);
                $table->text('content');
                $table->unsignedTinyInteger('estimate_points')->nullable();
                $table->boolean('explicit_agree')->nullable();
                $table->timestamps();
                $table->index(['story_estimation_run_id', 'round', 'id'], 'story_estimation_message_order');
            });
        }

        // A previous two-round/manual PO result has no proof of three-role consent.
        // Keep the entire row and its agent evidence, but never treat it as a final consensus.
        DB::table('story_estimation_runs')->whereNull('story_snapshot_hash')
            ->whereIn('status', ['RUNNING', 'REPORT_PENDING', 'COMPLETED'])
            ->update(['status' => 'LEGACY_UNVERIFIED']);

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS story_estimation_active_revision_unique
                ON story_estimation_runs (story_id, story_revision)
                WHERE status IN ('RUNNING', 'REPORT_PENDING', 'COMPLETED')");
        }
    }

    public function down(): void
    {
        // Deliberately non-destructive: rollback of consensus transcripts requires a
        // reviewed restore from the pre-migration database backup, not automatic DROP.
    }
};
