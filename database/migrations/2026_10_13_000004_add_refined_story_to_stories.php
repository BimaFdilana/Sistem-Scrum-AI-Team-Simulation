<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table): void {
            // Hasil akhir diskusi Fibonacci dari Scrum Master (keputusan Bima
            // 2026-10-13): versi refined menggantikan data asli sebagai acuan
            // eksekusi, sedangkan data asli tetap tersimpan untuk tab perbandingan.
            $table->string('refined_title')->nullable();
            $table->text('refined_purpose')->nullable();
            $table->text('refined_acceptance_criteria')->nullable();
            $table->timestamp('refined_at')->nullable();
            $table->unsignedBigInteger('refined_from_run_id')->nullable();
            $table->foreign('refined_from_run_id')->references('id')->on('story_estimation_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table): void {
            $table->dropForeign(['refined_from_run_id']);
            $table->dropColumn(['refined_title', 'refined_purpose', 'refined_acceptance_criteria', 'refined_at', 'refined_from_run_id']);
        });
    }
};
