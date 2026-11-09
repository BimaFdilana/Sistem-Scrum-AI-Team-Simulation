<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Waktu progres terakhir sebuah task.
 *
 * Tanpa penanda ini, task yang worker-nya menggantung terlihat sama dengan
 * task yang sedang bekerja: kartu tetap `running`, dan run menunggu sampai
 * jam habis. Watchdog memakai kolom ini untuk membedakan keduanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('execution_tasks', 'last_progress_at')) {
            return;
        }

        Schema::table('execution_tasks', function (Blueprint $table) {
            $table->timestamp('last_progress_at')->nullable()->after('attempt_seq');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('execution_tasks', 'last_progress_at')) {
            return;
        }

        Schema::table('execution_tasks', function (Blueprint $table) {
            $table->dropColumn('last_progress_at');
        });
    }
};
