<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->string('assignee_profile', 80);
            $table->string('status', 32)->default('BACKLOG');
            $table->unsignedInteger('story_revision');
            $table->unsignedInteger('revision')->default(1);
            $table->text('blocker_reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['story_id', 'status']);
            $table->index(['assignee_profile', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
