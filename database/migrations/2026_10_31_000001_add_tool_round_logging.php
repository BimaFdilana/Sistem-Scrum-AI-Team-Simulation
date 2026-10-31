<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diagnosis eksekusi #1 (31 Okt 2026): kegagalan 12 putaran tidak bisa
 * didiagnosis karena (a) transkrip per putaran tidak disimpan di mana pun
 * dan (b) ai_usage_records tidak tertaut ke run/task — model hanya bisa
 * ditebak dari jendela waktu. Migrasi ini menutup kedua lubang itu tanpa
 * mengubah data lama: kolom baru semuanya nullable, tabel baru kosong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_records', function (Blueprint $table) {
            $table->unsignedBigInteger('execution_run_id')->nullable()->after('model');
            $table->unsignedBigInteger('execution_task_id')->nullable()->after('execution_run_id');
            $table->string('source')->nullable()->after('execution_task_id');
            $table->index('execution_run_id');
            $table->index('execution_task_id');
        });

        Schema::create('agent_tool_rounds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('execution_run_id')->nullable()->index();
            $table->unsignedBigInteger('execution_task_id')->nullable()->index();
            $table->string('source')->default('execution');
            $table->string('probe_token')->nullable()->index();
            $table->unsignedInteger('round');
            $table->string('model');
            $table->json('tool_calls')->nullable();
            $table->json('outcomes')->nullable();
            $table->text('assistant_text')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_tool_rounds');
        Schema::table('ai_usage_records', function (Blueprint $table) {
            $table->dropIndex(['execution_run_id']);
            $table->dropIndex(['execution_task_id']);
            $table->dropColumn(['execution_run_id', 'execution_task_id', 'source']);
        });
    }
};
