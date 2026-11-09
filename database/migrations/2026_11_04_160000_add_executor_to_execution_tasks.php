<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pelaksana (executor) tiap task: hermes | opencode | internal.
 *
 * Tanpa kolom ini, perbandingan "Hermes vs OpenCode" hanya bisa ditebak dari
 * pesan, bukan diukur: berapa revisi, berapa lama, berapa yang gagal.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('execution_tasks', 'executor')) {
            return;
        }

        Schema::table('execution_tasks', function (Blueprint $table) {
            $table->string('executor', 20)->nullable()->after('assignee');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('execution_tasks', 'executor')) {
            return;
        }

        Schema::table('execution_tasks', function (Blueprint $table) {
            $table->dropColumn('executor');
        });
    }
};
