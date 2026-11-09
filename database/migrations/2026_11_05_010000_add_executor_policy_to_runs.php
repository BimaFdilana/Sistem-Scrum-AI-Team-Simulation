<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pemilihan pelaksana task secara otomatis.
 *
 * Sebelumnya OpenCode hanya bisa dinyalakan lewat env dan berlaku global —
 * PO harus menyalakan/mematikan manual sebelum tiap story, dan perbandingan
 * jadi bercampur kalau lupa. Sekarang kebijakan disimpan per run:
 *   auto      = BE/FE ke OpenCode, sisanya Hermes (default)
 *   hermes    = semua lewat Hermes
 *   opencode  = BE/FE wajib OpenCode
 *   ab        = selang-seling per task untuk perbandingan
 * Ditambah executor_override per task untuk fallback otomatis.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('execution_runs', 'executor_policy')) {
            Schema::table('execution_runs', function (Blueprint $table) {
                $table->string('executor_policy', 20)->default('auto')->after('status');
            });
        }

        if (! Schema::hasColumn('execution_tasks', 'executor_override')) {
            Schema::table('execution_tasks', function (Blueprint $table) {
                $table->string('executor_override', 20)->nullable()->after('executor');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('execution_runs', 'executor_policy')) {
            Schema::table('execution_runs', function (Blueprint $table) {
                $table->dropColumn('executor_policy');
            });
        }

        if (Schema::hasColumn('execution_tasks', 'executor_override')) {
            Schema::table('execution_tasks', function (Blueprint $table) {
                $table->dropColumn('executor_override');
            });
        }
    }
};
