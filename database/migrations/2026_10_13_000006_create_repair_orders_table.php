<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Menu "Perintah Perbaikan":
// PO memerintah SM dari chat -> SM klasifikasikan -> ringkasan + konfirmasi PO ->
// perintah masuk tabel ini (terpisah dari Sprint Backlog) -> PO jalankan via
// pipeline eksekusi yang sama (FE/BE -> QA -> merge -> keputusan final PO).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repair_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('summary')->nullable(); // ringkasan perintah versi SM
            $table->text('original_message'); // pesan asli PO di chat
            $table->boolean('scope_risk')->default(false); // terdeteksi berisiko (hapus data/destruktif)
            $table->text('risk_note')->nullable();
            // DRAFT, CONFIRMED, RUNNING, COMPLETED, FAILED, BLOCKED, CANCELLED
            $table->string('status', 16)->default('DRAFT');
            $table->string('acceptance_status', 16)->nullable(); // BERHASIL | GAGAL (keputusan final PO)
            $table->timestamp('accepted_at')->nullable();
            $table->string('conversation_uuid', 36)->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        $this->makeStoryIdNullable();

        Schema::table('execution_runs', function (Blueprint $table): void {
            $table->foreignId('repair_order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->index(['repair_order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('execution_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('repair_order_id');
        });
        Schema::dropIfExists('repair_orders');
        // story_id dibiarkan nullable pada rollback (tidak merusak data lama).
    }

    /**
     * execution_runs.story_id awalnya NOT NULL. Perintah perbaikan tidak punya
     * story, jadi kolomnya harus nullable. SQLite tidak mendukung ALTER COLUMN,
     * sehingga tabel dibangun ulang; MySQL cukup MODIFY.
     */
    private function makeStoryIdNullable(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::create('execution_runs_new', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('story_id')->nullable()->constrained()->cascadeOnDelete();
                $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
                $table->string('status', 32)->default('QUEUED');
                $table->text('summary')->nullable();
                $table->string('error_code', 64)->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->index(['story_id', 'status']);
            });
            DB::statement('INSERT INTO execution_runs_new (id, story_id, requested_by, status, summary, error_code, completed_at, created_at, updated_at) SELECT id, story_id, requested_by, status, summary, error_code, completed_at, created_at, updated_at FROM execution_runs');
            Schema::drop('execution_runs');
            Schema::rename('execution_runs_new', 'execution_runs');

            return;
        }

        DB::statement('ALTER TABLE execution_runs MODIFY story_id BIGINT UNSIGNED NULL');
    }
};
