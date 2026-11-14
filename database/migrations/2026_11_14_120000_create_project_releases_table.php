<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Riwayat rilis project ke GitHub (Demo & Rilis). */
    public function up(): void
    {
        Schema::create('project_releases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16); // PUSHED, FAILED
            $table->string('commit_sha', 64)->nullable();
            $table->string('previous_sha', 64)->nullable();
            $table->string('backup_tag', 120)->nullable();
            $table->json('items')->nullable();
            $table->unsignedInteger('commit_count')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_releases');
    }
};
