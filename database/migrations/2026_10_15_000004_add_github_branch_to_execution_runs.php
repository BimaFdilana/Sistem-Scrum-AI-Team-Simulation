<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Simpan nama branch GitHub per eksekusi story (format: story-{id}-{slug}).
     * Null bila GitHub tidak terhubung — flow lokal tetap jalan seperti biasa.
     */
    public function up(): void
    {
        Schema::table('execution_runs', function (Blueprint $table): void {
            $table->string('github_branch')->nullable()->after('error_code');
        });
    }

    public function down(): void
    {
        Schema::table('execution_runs', function (Blueprint $table): void {
            $table->dropColumn('github_branch');
        });
    }
};
