<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Urutan eksekusi berurutan + sinkron (keputusan Bima 2026-10-31):
 * - sort_order: urutan dependensi task dalam satu run (urutan array
 *   breakdown SM = urutan eksekusi; 0 = task pertama).
 * - chain_base: cabang task sebelumnya yang menjadi basis worktree task
 *   ini (null = baseline). Dipakai agar diff QA tetap per task, bukan
 *   kumulatif terhadap baseline.
 * Default sort_order 0 menjaga run lama tetap terurut sesuai id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('execution_tasks', function (Blueprint $table): void {
            $table->unsignedInteger('sort_order')->default(0)->after('status');
            $table->string('chain_base', 128)->nullable()->after('branch');
            $table->index(['execution_run_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::table('execution_tasks', function (Blueprint $table): void {
            $table->dropIndex(['execution_run_id', 'sort_order']);
            $table->dropColumn(['sort_order', 'chain_base']);
        });
    }
};
