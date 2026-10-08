<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sprints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('goal');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 32)->default('PLANNING');
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedInteger('scope_approved_revision')->nullable();
            $table->foreignId('scope_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scope_approved_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sprints');
    }
};
