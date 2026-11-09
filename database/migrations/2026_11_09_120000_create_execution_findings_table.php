<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('execution_findings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('execution_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('execution_task_id')->nullable()->constrained()->nullOnDelete();
            $table->string('assignee')->nullable();
            $table->string('severity', 16)->default('medium');
            $table->string('file');
            $table->unsignedInteger('line')->nullable();
            $table->text('issue');
            $table->text('verify')->nullable();
            // OPEN (harus diperbaiki) | DISMISSED (SM memenangkan developer) | RESOLVED
            $table->string('status', 16)->default('OPEN');
            $table->unsignedSmallInteger('round')->default(1);
            $table->string('dev_stance', 16)->nullable();
            $table->text('dev_reason')->nullable();
            $table->string('sm_winner', 16)->nullable();
            $table->text('sm_reason')->nullable();
            $table->timestamps();

            $table->index(['execution_run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_findings');
    }
};
