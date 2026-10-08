<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_estimation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('story_revision');
            $table->string('status', 24)->default('RUNNING');
            $table->longText('summary')->nullable();
            $table->unsignedTinyInteger('approved_points')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['story_id', 'created_at']);
            $table->index(['story_id', 'story_revision', 'status']);
        });

        Schema::create('story_estimation_agent_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('story_estimation_run_id')->constrained()->cascadeOnDelete();
            $table->string('profile_slug', 80);
            $table->unsignedTinyInteger('round');
            $table->string('status', 24)->default('RUNNING');
            $table->unsignedTinyInteger('estimate_points')->nullable();
            $table->text('rationale')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['story_estimation_run_id', 'profile_slug', 'round'], 'story_estimation_agent_run_unique');
            $table->index(['profile_slug', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_estimation_agent_runs');
        Schema::dropIfExists('story_estimation_runs');
    }
};
