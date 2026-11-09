<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('execution_tasks', function (Blueprint $table): void {
            // Berapa kali watchdog menjalankan ulang task ini karena macet.
            $table->unsignedSmallInteger('stall_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('execution_tasks', function (Blueprint $table): void {
            $table->dropColumn('stall_count');
        });
    }
};
