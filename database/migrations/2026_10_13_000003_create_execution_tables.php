<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Pipeline Eksekusi User Story:
// PO klik Eksekusi -> SM bagi tugas -> PO setujui izin (menu Accepted) ->
// FE/BE kerjakan di worktree terisolasi (patch diterapkan sistem) ->
// QA review (maks 2 revisi) -> merge -> SM rangkum ke PO.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('execution_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            // QUEUED, BREAKDOWN, AWAITING_PERMISSION, EXECUTING, REVIEWING,
            // MERGING, COMPLETED, FAILED, BLOCKED
            $table->string('status', 32)->default('QUEUED');
            $table->text('summary')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['story_id', 'status']);
        });

        Schema::create('permission_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('execution_run_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->json('payload')->nullable(); // rencana perubahan file per task
            $table->string('status', 16)->default('PENDING'); // PENDING, APPROVED, REJECTED
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('execution_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('execution_run_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('assignee', 32); // frontend-developer | backend-developer
            // PENDING, IN_PROGRESS, IN_REVIEW, REVISION, APPROVED, FAILED, BLOCKED
            $table->string('status', 16)->default('PENDING');
            $table->string('worktree_path', 500)->nullable();
            $table->string('branch', 128)->nullable();
            $table->unsignedTinyInteger('revision_count')->default(0);
            $table->text('qa_feedback')->nullable();
            $table->timestamps();
            $table->index(['execution_run_id', 'status']);
        });

        Schema::create('execution_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('execution_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('execution_task_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sender_slug', 64);
            $table->string('recipient_slug', 64);
            $table->text('content');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['execution_run_id', 'created_at']);
        });

        Schema::create('demo_servers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('port');
            $table->unsignedInteger('pid')->nullable();
            $table->string('status', 16)->default('RUNNING'); // RUNNING, STOPPED, EXPIRED
            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_servers');
        Schema::dropIfExists('execution_messages');
        Schema::dropIfExists('execution_tasks');
        Schema::dropIfExists('permission_requests');
        Schema::dropIfExists('execution_runs');
    }
};
