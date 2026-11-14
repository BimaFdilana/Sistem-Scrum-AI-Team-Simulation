<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Tanya Scrum Master" kini: SM memahami perintah lebih dulu (judul + kriteria),
     * PO mengonfirmasi atau mengoreksi, baru tim bekerja. Perintah besar dipecah
     * menjadi beberapa bagian. Story dibuat saat konfirmasi, jadi story_id boleh kosong.
     */
    public function up(): void
    {
        Schema::table('chat_commands', function (Blueprint $table): void {
            $table->string('phase', 24)->default('CONFIRMED')->after('command'); // UNDERSTANDING, AWAITING_CONFIRMATION, CONFIRMED, CANCELLED, NOT_UNDERSTOOD
            $table->string('title', 200)->nullable()->after('phase');
            $table->json('criteria')->nullable()->after('title');
            $table->text('hint')->nullable()->after('criteria');
            $table->foreignId('parent_id')->nullable()->after('id')->constrained('chat_commands')->nullOnDelete();
            $table->unsignedTinyInteger('part_index')->nullable()->after('parent_id');
            $table->unsignedTinyInteger('part_total')->nullable()->after('part_index');
            $table->unsignedBigInteger('story_id')->nullable()->change();
        });

        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->foreignId('chat_command_id')->nullable()->after('execution_run_id')
                ->constrained('chat_commands')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('chat_command_id');
        });
        Schema::table('chat_commands', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['phase', 'title', 'criteria', 'hint', 'part_index', 'part_total']);
        });
    }
};
