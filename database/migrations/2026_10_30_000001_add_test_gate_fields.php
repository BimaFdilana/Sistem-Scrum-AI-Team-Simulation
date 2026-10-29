<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('execution_tasks', function (Blueprint $table) {
            $table->text('diff_snapshot')->nullable()->after('qa_feedback');
            $table->text('gate_output')->nullable()->after('diff_snapshot');
            $table->unsignedTinyInteger('gate_fail_count')->default(0)->after('gate_output');
            $table->string('last_error')->nullable()->after('gate_fail_count');
        });
        Schema::table('projects', function (Blueprint $table) {
            // Perintah tes opsional per project (default MATI = null).
            // Diisi PO di halaman project; dijalankan di worktree task
            // sebagai gerbang QA sebelum review. Kosong = tidak dijalankan.
            $table->string('test_command')->nullable()->after('stack');
        });
    }

    public function down(): void
    {
        Schema::table('execution_tasks', function (Blueprint $table) {
            $table->dropColumn(['diff_snapshot', 'gate_output', 'gate_fail_count', 'last_error']);
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('test_command');
        });
    }
};
