<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Simpan repo GitHub PO yang terhubung dengan project (Fase 2).
     * Nullable: project yang dibuat sebelum integrasi GitHub tetap jalan.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('github_owner', 255)->nullable()->after('source_error');
            $table->string('github_repo', 255)->nullable()->after('github_owner');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(['github_owner', 'github_repo']);
        });
    }
};
