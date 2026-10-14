<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Simpan path gambar lampiran chat agar tidak hilang setelah dikirim ke AI.
     */
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->string('image_path')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->dropColumn('image_path');
        });
    }
};
