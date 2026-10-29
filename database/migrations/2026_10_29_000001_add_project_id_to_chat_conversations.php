<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Percakapan chat Scrum Master bisa terikat ke satu project ScrumSpace
     * (dipilih lewat dropdown di halaman chat) atau tanpa project (umum).
     * Percakapan lama (project_id NULL) otomatis menjadi chat umum.
     */
    public function up(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table): void {
            $table->foreignId('project_id')->nullable()->after('agent_profile')
                ->constrained()->nullOnDelete();
            $table->index(['user_id', 'agent_profile', 'project_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'agent_profile', 'project_id', 'updated_at']);
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
