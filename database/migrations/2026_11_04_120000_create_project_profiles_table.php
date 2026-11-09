<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profil runtime per project.
 *
 * Sebelumnya aplikasi mengasumsikan satu PHP untuk semua project, sehingga
 * project Laravel 8 (butuh <=8.4) dan Laravel 11 (butuh >=8.2) tidak bisa
 * hidup bersama. Profil ini menyimpan hasil deteksi saat import dan dipakai
 * oleh provisioner, worker, serta demo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_profiles')) {
            return;
        }

        Schema::create('project_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('php_binary')->nullable();
            $table->string('php_version', 10)->nullable();
            $table->string('laravel_version', 20)->nullable();
            $table->string('db_engine', 20)->default('sqlite');
            $table->boolean('needs_node')->default(false);
            $table->string('subdir')->nullable();
            $table->string('provision_status', 20)->default('PENDING');
            $table->text('provision_error')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_profiles');
    }
};
