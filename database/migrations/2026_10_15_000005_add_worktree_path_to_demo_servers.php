<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Simpan path worktree demo (bila demo jalan dari branch integration GitHub).
     * Null bila demo jalan dari baseline lokal seperti biasa.
     */
    public function up(): void
    {
        Schema::table('demo_servers', function (Blueprint $table): void {
            $table->string('worktree_path')->nullable()->after('pid');
        });
    }

    public function down(): void
    {
        Schema::table('demo_servers', function (Blueprint $table): void {
            $table->dropColumn('worktree_path');
        });
    }
};
