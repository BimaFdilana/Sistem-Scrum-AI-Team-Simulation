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
    public function submitForQa(ExecutionRun $run, ExecutionTask $task, string $diffSnapshot): void
    {
        if (($run->qa_mode ?: 'per_task') === 'batch') {
            // QA batch: task berhenti di QA_PENDING, dinilai sekali di akhir.
            $task->forceFill(['diff_snapshot' => $diffSnapshot])->save();
            app(BatchQa::class)->taskReady($run, $task);

            return;
        }

        $task->forceFill(['status' => 'IN_REVIEW', 'diff_snapshot' => $diffSnapshot])->save();
        $run->forceFill(['status' => 'REVIEWING'])->save();
        ReviewExecutionTaskJob::dispatch($run->id, $task->id);
    }
}
