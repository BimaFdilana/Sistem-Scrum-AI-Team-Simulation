<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('execution_tasks', function (Blueprint $table) {
            $table->unsignedTinyInteger('patch_retry_count')->default(0)->after('revision_count');
        });
    }

    public function down(): void
    {
        Schema::table('execution_tasks', function (Blueprint $table) {
            $table->dropColumn('patch_retry_count');
        });
    }
};
