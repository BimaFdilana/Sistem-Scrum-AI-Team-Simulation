<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Flag target branch untuk perintah perbaikan (mis. 'integration' untuk
     * perbaikan demo). Null = flow normal (worktree story seperti biasa).
     */
    public function up(): void
    {
        Schema::table('repair_orders', function (Blueprint $table): void {
            $table->string('target_branch')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('repair_orders', function (Blueprint $table): void {
            $table->dropColumn('target_branch');
        });
    }
};
