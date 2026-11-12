<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Ubah kode langsung" diganti "Tanya Scrum Master": perintah PO di chat
     * dikerjakan tim (SM memecah, BE/FE mengerjakan, QA memeriksa) lewat alur
     * eksekusi biasa, lalu SM melapor ke chat. Tiap perintah disimpan sebagai
     * story ber-origin CHAT tanpa sprint, jadi tidak muncul di Sprint Backlog.
     */
    public function up(): void
    {
        if (Schema::hasColumn('chat_messages', 'direct_change_id')) {
            DB::table('chat_messages')->where('kind', 'change')->delete();
            Schema::table('chat_messages', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('direct_change_id');
            });
        }
        Schema::dropIfExists('direct_changes');

        Schema::table('stories', function (Blueprint $table): void {
            $table->string('origin', 16)->default('BACKLOG')->after('project_id');
        });

        Schema::create('chat_commands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chat_conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sprint_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->text('command');
            $table->json('reported')->nullable(); // status run yang sudah dilaporkan, per run
            $table->timestamps();
            $table->unique('story_id');
        });

        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->foreignId('execution_run_id')->nullable()->after('link_label')
                ->constrained('execution_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('execution_run_id');
        });
        Schema::dropIfExists('chat_commands');
        Schema::table('stories', function (Blueprint $table): void {
            $table->dropColumn('origin');
        });
    }
};
