<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bukti kesehatan demo.
 *
 * Sebelumnya status demo hanya RUNNING/tidak, dan health check cuma melihat
 * HTTP `/` < 500 — halaman login pun lolos. Kolom ini menyimpan hasil
 * pemeriksaan terakhir supaya keputusan PO bisa merujuk bukti.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('demo_servers', 'health_status')) {
            return;
        }

        Schema::table('demo_servers', function (Blueprint $table) {
            $table->string('health_status', 20)->nullable()->after('status');
            $table->unsignedSmallInteger('health_http_status')->nullable()->after('health_status');
            $table->text('health_error')->nullable()->after('health_http_status');
            $table->timestamp('health_checked_at')->nullable()->after('health_error');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('demo_servers', 'health_status')) {
            return;
        }

        Schema::table('demo_servers', function (Blueprint $table) {
            $table->dropColumn(['health_status', 'health_http_status', 'health_error', 'health_checked_at']);
        });
    }
};
