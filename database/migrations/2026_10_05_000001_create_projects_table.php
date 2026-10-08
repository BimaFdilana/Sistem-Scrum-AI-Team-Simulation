<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('project_type', 32);
            $table->string('stack', 100)->nullable();
            $table->string('status', 32)->default('SETUP_REQUIRED');
            $table->timestamps();

            $table->index(['project_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
