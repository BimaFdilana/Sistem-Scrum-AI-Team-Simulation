<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->longText('acceptance_criteria');
            $table->unsignedTinyInteger('priority')->default(3);
            $table->unsignedTinyInteger('estimate_points')->nullable();
            $table->string('scope_status', 32)->default('NEEDS_REFINEMENT');
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedInteger('scope_approved_revision')->nullable();
            $table->foreignId('scope_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scope_approved_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'scope_status', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stories');
    }
};
