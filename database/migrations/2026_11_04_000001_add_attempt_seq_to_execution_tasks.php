<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nomor percobaan task yang monoton.
 *
 * Dulu nomor percobaan hanya diturunkan dari revision_count, sehingga PO
 * yang mereset hitungan revisi (setelah task diblokir) memakai ulang nomor
 * lama: nama cabang dan correlation key kartu kanban sama lagi, kartu lama
 * yang sudah blocked/done terpakai ulang, dan run langsung gagal.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('execution_tasks', 'attempt_seq')) {
            return;
        }

        Schema::table('execution_tasks', function (Blueprint $table) {
            $table->unsignedInteger('attempt_seq')->default(0)->after('revision_count');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('execution_tasks', 'attempt_seq')) {
            return;
        }

        Schema::table('execution_tasks', function (Blueprint $table) {
            $table->dropColumn('attempt_seq');
        });
    }
};
