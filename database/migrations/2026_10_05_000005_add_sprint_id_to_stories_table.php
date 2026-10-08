<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table): void {
            $table->foreignId('sprint_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
            $table->index(['sprint_id', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table): void {
            $table->dropForeign(['sprint_id']);
            $table->dropIndex(['sprint_id', 'priority']);
            $table->dropColumn('sprint_id');
        });
    }
};
