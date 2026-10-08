<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sprints', function (Blueprint $table): void {
            $table->text('goal')->nullable()->change();
            $table->string('goal_source', 16)->default('LEGACY')->after('goal');
            $table->date('start_date')->nullable()->change();
            $table->date('end_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('sprints')->whereNull('goal')->update(['goal' => 'Sprint Goal belum dirumuskan.']);
        DB::table('sprints')->whereNull('start_date')->update([
            'start_date' => DB::raw('DATE(created_at)'),
        ]);
        DB::table('sprints')->whereNull('end_date')->update([
            'end_date' => DB::raw('DATE(created_at)'),
        ]);

        Schema::table('sprints', function (Blueprint $table): void {
            $table->text('goal')->nullable(false)->change();
            $table->date('start_date')->nullable(false)->change();
            $table->date('end_date')->nullable(false)->change();
            $table->dropColumn('goal_source');
        });
    }
};
