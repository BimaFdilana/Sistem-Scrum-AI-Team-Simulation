<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Perubahan kode langsung dari chat: PO meminta, Developer (mode langsung)
     * mengerjakan di cabang sendiri lalu digabung ke kode utama, tanpa alur
     * Scrum (estimasi, izin, QA). Setiap perubahan bisa dibatalkan (git revert).
     */
    public function up(): void
    {
        Schema::create('direct_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sprint_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chat_conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->text('request');
            $table->string('status', 16)->default('QUEUED'); // QUEUED, RUNNING, DONE, NO_CHANGES, FAILED, REVERTED
            $table->string('base_branch', 128)->nullable();
            $table->string('branch', 128)->nullable();
            $table->string('commit_before', 64)->nullable();
            $table->string('commit_after', 64)->nullable();
            $table->json('files')->nullable();
            $table->text('summary')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('reverted_at')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'status']);
        });

        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->foreignId('direct_change_id')->nullable()->after('link_label')
                ->constrained('direct_changes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('direct_change_id');
        });
        Schema::dropIfExists('direct_changes');
    }
};
