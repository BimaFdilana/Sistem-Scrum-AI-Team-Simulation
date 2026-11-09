<?php

namespace App\Support\Execution;

use App\Jobs\ReviewExecutionTaskJob;
use App\Models\ExecutionRun;
use App\Models\ExecutionTask;

/**
 * Satu-satunya pintu setelah developer selesai mengerjakan task: serahkan ke QA
 * sesuai mode QA run. Semua jalur eksekutor (patch/tool, OpenCode, Kanban/Hermes)
 * wajib lewat sini. Dulu tiap jalur memutuskan sendiri, dan jalur OpenCode serta
 * Kanban selalu memakai review per task sehingga EXECUTION_QA_MODE=batch (QA
 * berbukti, dialog temuan, penyerahan ke PO) tidak pernah berjalan di live.
 */
class TaskCompletion
{
    /**
     * Agen menyatakan selesai tetapi tidak ada file yang berubah. Bisa berarti kodenya
     * SUDAH memenuhi task (mis. include yang sudah ada), bisa juga agen tidak bekerja.
     * Run tidak digagalkan: QA memutuskan, dengan isi file target sebagai bukti.
     */
    public function submitNoChange(ExecutionRun $run, ExecutionTask $task, string $reason): void
    {
        $run->messages()->create([
            'execution_task_id' => $task->id,
            'sender_slug' => $task->assignee,
            'recipient_slug' => 'qa-engineer',
            'content' => 'Tidak ada perubahan file: '.$reason.' Task dinilai sudah terpenuhi oleh kode yang ada; QA memverifikasi isi file target.',
            'metadata' => ['no_change' => true, 'revision' => $task->revision_count],
        ]);

        $this->submitForQa($run, $task, '');
    }

    /** Apakah task ini diserahkan ke QA tanpa perubahan file? */
    public static function isNoChange(ExecutionRun $run, ExecutionTask $task): bool
    {
        return $run->messages()->where('execution_task_id', $task->id)->get()
            ->contains(fn ($message) => data_get($message->metadata, 'no_change') === true);
    }

    public function submitForQa(ExecutionRun $run, ExecutionTask $task, string $diffSnapshot): void
    {
        if (($run->qa_mode ?: 'per_task') === 'batch') {
            // QA batch: task berhenti di QA_PENDING, dinilai sekali di akhir.
            $task->forceFill(['diff_snapshot' => $diffSnapshot])->save();
            app(BatchQa::class)->taskReady($run, $task);
            // Tanpa review per task, tidak ada yang mengirim task berikutnya: kirim di sini.
            // Dulu run mode langsung macet setelah task pertama dengan sisa task PENDING.
            app(ExecutionScheduler::class)->dispatchReady($run->fresh());

            return;
        }

        $task->forceFill(['status' => 'IN_REVIEW', 'diff_snapshot' => $diffSnapshot])->save();
        $run->forceFill(['status' => 'REVIEWING'])->save();
        ReviewExecutionTaskJob::dispatch($run->id, $task->id);
    }
}
