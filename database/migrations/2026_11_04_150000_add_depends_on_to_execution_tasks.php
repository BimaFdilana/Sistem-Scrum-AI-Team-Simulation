<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dependensi antar task dalam satu run.
 *
 * Disimpan sebagai daftar id task (JSON). Task hanya boleh dijalankan setelah
 * semua dependensinya APPROVED. Bila kosong, urutan ditentukan sort_order dan
 * benturan file (dua task yang menyentuh file sama tidak boleh paralel).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('execution_tasks', 'depends_on')) {
            return;
        }

        Schema::table('execution_tasks', function (Blueprint $table) {
            $table->json('depends_on')->nullable()->after('sort_order');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('execution_tasks', 'depends_on')) {
            return;
        }

        Schema::table('execution_tasks', function (Blueprint $table) {
            $table->dropColumn('depends_on');
        });
    }
};
