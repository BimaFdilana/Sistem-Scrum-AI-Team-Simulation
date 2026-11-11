<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Satu percakapan Scrum Master = satu sprint. Percakapan lama (sprint_id
     * NULL) tetap ada sebagai "arsip chat lama" dan tidak dicampur ke sprint.
     * Pesan kini punya jenis: 'chat' (obrolan PO↔SM) atau 'report' (laporan
     * otomatis SM, mis. hasil estimasi/eksekusi) lengkap dengan tautan sumber.
     */
    public function up(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table): void {
            $table->foreignId('sprint_id')->nullable()->after('project_id')
                ->constrained('sprints')->nullOnDelete();
            $table->index(['user_id', 'agent_profile', 'sprint_id', 'updated_at'], 'chat_conv_sprint_idx');
        });

        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->string('kind', 16)->default('chat')->after('role');
            $table->string('link_url', 500)->nullable()->after('image_path');
            $table->string('link_label', 80)->nullable()->after('link_url');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'link_url', 'link_label']);
        });

        Schema::table('chat_conversations', function (Blueprint $table): void {
            $table->dropIndex('chat_conv_sprint_idx');
            $table->dropConstrainedForeignId('sprint_id');
        });
    }
};
