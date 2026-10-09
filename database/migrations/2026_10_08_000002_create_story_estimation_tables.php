<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_estimation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('story_revision');
            $table->string('story_snapshot_hash', 64)->nullable();
            $table->string('status', 24)->default('RUNNING');
            $table->longText('summary')->nullable();
            $table->unsignedTinyInteger('consensus_points')->nullable();
            $table->timestamp('report_sent_at')->nullable();
            $table->unsignedTinyInteger('approved_points')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['story_id', 'created_at']);
            $table->index(['story_id', 'story_revision', 'status']);
        });

        DB::statement("CREATE UNIQUE INDEX story_estimation_active_revision_unique
            ON story_estimation_runs (story_id, story_revision)
            WHERE status IN ('RUNNING', 'REPORT_PENDING', 'COMPLETED')");

        Schema::create('story_estimation_agent_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('story_estimation_run_id')->constrained()->cascadeOnDelete();
            $table->string('profile_slug', 80);
            $table->unsignedTinyInteger('round');
            $table->string('status', 24)->default('RUNNING');
            $table->unsignedTinyInteger('estimate_points')->nullable();
            $table->text('rationale')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['story_estimation_run_id', 'profile_slug', 'round'], 'story_estimation_agent_run_unique');
            $table->index(['profile_slug', 'created_at']);
        });

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

    public function down(): void
    {
        Schema::dropIfExists('story_estimation_messages');
        Schema::dropIfExists('story_estimation_agent_runs');
        Schema::dropIfExists('story_estimation_runs');
    }
};
