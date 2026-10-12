<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mencatat cara hasil final dicapai: 'unanimous' (mufakat bulat) atau
// 'deadlock_max' (deadlock → Scrum Master menerapkan maksimum, opsi B).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('story_estimation_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('story_estimation_runs', 'consensus_method')) {
                $table->string('consensus_method', 20)->nullable()->after('consensus_points');
            }
        });
    }

    public function down(): void
    {
        Schema::table('story_estimation_runs', function (Blueprint $table): void {
            if (Schema::hasColumn('story_estimation_runs', 'consensus_method')) {
                $table->dropColumn('consensus_method');
            }
        });
    }
};
