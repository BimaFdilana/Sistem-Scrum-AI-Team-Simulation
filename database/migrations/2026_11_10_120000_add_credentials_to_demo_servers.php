<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demo_servers', function (Blueprint $table): void {
            // Akun masuk demo (terenkripsi lewat cast model): kata sandi acak + daftar akun.
            $table->text('credentials')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('demo_servers', function (Blueprint $table): void {
            $table->dropColumn('credentials');
        });
    }
};
