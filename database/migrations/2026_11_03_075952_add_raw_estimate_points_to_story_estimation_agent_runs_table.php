<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Simpan poin ASLI yang dikembalikan agent sebelum normalisasi.
 *
 * Kasus US-03 (2 Nov 2026): ketiga spesialis menjawab poin 2 — angka
 * Fibonacci yang jujur, tapi di luar daftar sah [1,2,3,5,8,13] — dan
 * seluruh estimasi gagal INVALID_JSON_SCHEMA. Setelah normalisasi
 * (2 -> 3), nilai asli tetap dicatat di sini supaya keputusan sistem
 * bisa diaudit: berapa sering agent keluar dari daftar, dan ke arah
 * mana pembulatannya.
 *
 * Nullable: vote yang poinnya sudah sah (mayoritas) tidak mengisi apa pun.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('story_estimation_agent_runs', function (Blueprint $table) {
            $table->unsignedSmallInteger('raw_estimate_points')->nullable()->after('estimate_points');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('story_estimation_agent_runs', function (Blueprint $table) {
            $table->dropColumn('raw_estimate_points');
        });
    }
};
