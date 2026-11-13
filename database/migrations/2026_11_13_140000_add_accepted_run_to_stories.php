<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catat eksekusi mana yang diputuskan PO. Tanpa ini, halaman eksekusi lama
     * yang gagal ikut menampilkan "Keputusan final PO: Berhasil" seolah-olah
     * eksekusi gagal itulah yang diterima (kasus US-38: diterima di #22, tampil di #21).
     */
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table): void {
            $table->foreignId('accepted_execution_run_id')->nullable()->after('accepted_at')
                ->constrained('execution_runs')->nullOnDelete();
        });

        // Data lama: BERHASIL hanya mungkin sah pada run COMPLETED terbaru; GAGAL pada run terbaru.
        foreach (DB::table('stories')->whereNotNull('acceptance_status')->get(['id', 'acceptance_status']) as $story) {
            $query = DB::table('execution_runs')->where('story_id', $story->id);
            if ($story->acceptance_status === 'BERHASIL') {
                $query->where('status', 'COMPLETED');
            }
            $runId = $query->max('id');
            if ($runId !== null) {
                DB::table('stories')->where('id', $story->id)->update(['accepted_execution_run_id' => $runId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('accepted_execution_run_id');
        });
    }
};
