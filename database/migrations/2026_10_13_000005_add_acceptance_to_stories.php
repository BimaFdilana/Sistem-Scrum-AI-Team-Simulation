<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table): void {
            // Keputusan final PO (keputusan Bima 2026-10-13): Berhasil/Gagal
            // mengunci story agar tidak bisa dieksekusi lagi.
            $table->string('acceptance_status', 16)->nullable();
            $table->timestamp('accepted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table): void {
            $table->dropColumn(['acceptance_status', 'accepted_at']);
        });
    }
};
