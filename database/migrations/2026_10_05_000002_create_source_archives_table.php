<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_archives', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('storage_path', 500);
            $table->string('original_name', 255);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('status', 32)->default('QUARANTINED');
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_archives');
    }
};
