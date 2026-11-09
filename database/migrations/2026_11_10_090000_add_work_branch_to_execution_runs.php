<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('execution_runs', function (Blueprint $table): void {
            // Mode direct: tiap run bekerja di branch sendiri; base_branch = tujuan merge.
            $table->string('base_branch')->nullable();
            $table->string('work_branch')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('execution_runs', function (Blueprint $table): void {
            $table->dropColumn(['base_branch', 'work_branch']);
        });
    }
};
