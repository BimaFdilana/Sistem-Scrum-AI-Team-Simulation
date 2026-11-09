<?php

namespace App\Support\Execution;

use App\Jobs\BatchReviewExecutionJob;
use App\Models\ExecutionRun;
use App\Models\ExecutionTask;

/**
 * Pengatur QA batch: satu penilaian di akhir, perbaikan terarah ke role pemilik
 * file, dan batas putaran yang ditegakkan di kode (bukan di prompt).
 */
class BatchQa
{
    public const PENDING = 'QA_PENDING';

    /** File → role pemilik. */
    public function roleForFile(string $file): string
    {
        $file = ltrim($file, '/');

        foreach (['resources/views/', 'resources/js/', 'public/css/', 'public/js/', 'public/assets/'] as $prefix) {
            if (str_starts_with($file, $prefix)) {
                return 'frontend-developer';
            }
        }

        foreach (['app/', 'routes/', 'database/', 'config/', 'tests/', 'bootstrap/'] as $prefix) {
            if (str_starts_with($file, $prefix)) {
                return 'backend-developer';
            }
        }

        return 'backend-developer';
    }

    /** @param array<int, string> $files */
    public function rolesForFiles(array $files): array
    {
        $roles = array_map(fn (string $f) => $this->roleForFile($f), $files);

        return array_values(array_unique($roles));
    }

    /** Tandai satu task siap dinilai QA, lalu mulai review bila semua sudah siap. */
    public function taskReady(ExecutionRun $run, ExecutionTask $task): void
    {
        $task->forceFill(['status' => self::PENDING])->save();
        $this->maybeStart($run);
    }

    /** Mulai (atau tutup) review batch ketika tidak ada lagi pekerjaan aktif. */
    public function maybeStart(ExecutionRun $run): void
    {
        $run->refresh();
        if (! in_array($run->status, ['EXECUTING', 'REVIEWING', 'QA_REVIEW'], true)) {
            return;
        }

        $busy = $run->tasks()->whereIn('status', ['PENDING', 'IN_PROGRESS', 'REVISION'])->exists();
        if ($busy) {
            return;
        }

        $pending = $run->tasks()->where('status', self::PENDING)->count();
        if ($pending === 0) {
            return;
        }

        // Batas putaran: setelah perbaikan terakhir, tutup tanpa review kedua.
        // qa_rounds bertambah setiap QA memberi temuan. Bila sudah mencapai
        // batas, perbaikan berikutnya adalah yang terakhir: tutup tanpa review.
        if ((int) $run->qa_rounds >= (int) config('execution.qa_max_repair_rounds', 1)) {
            $this->finalize($run, 'Perbaikan QA sudah diterapkan pada putaran terakhir; run ditutup tanpa review kedua.');

            return;
        }

        if ($run->qa_started_at !== null && $run->status === 'QA_REVIEW') {
            return;
        }

        $run->forceFill(['status' => 'QA_REVIEW', 'qa_started_at' => now()])->save();
        BatchReviewExecutionJob::dispatch($run->id);
    }

    /**
     * Setujui semua task yang menunggu lalu selesaikan run.
     * Mode worktree tetap melewati merge; mode direct langsung selesai.
     */
    public function finalize(ExecutionRun $run, string $note): void
    {
        $run->tasks()->where('status', self::PENDING)->update(['status' => 'APPROVED', 'qa_feedback' => null]);

        $run->messages()->create([
            'sender_slug' => 'qa-engineer',
            'recipient_slug' => 'product-owner',
            'content' => $note,
            'metadata' => ['batch_qa' => true, 'rounds' => (int) $run->qa_rounds],
        ]);

        $range = '';
        if (($run->mode ?: config('execution.mode', 'worktree')) === 'direct') {
            $project = $run->project();
            $first = $run->tasks()->whereNotNull('chain_base')->min('chain_base');
            if ($project && $first) {
                try {
                    $head = app(\App\Support\Workspace\WorktreeManager::class)->head($project->workspace_path);
                    $range = ' · commit '.substr((string) $first, 0, 8).'..'.substr($head, 0, 8);
                } catch (\Throwable) {
                    $range = '';
                }
            }
        }

        $run->forceFill([
            'qa_finished_at' => now(),
            'summary' => trim((string) $run->summary."\nQA (".(int) $run->qa_rounds.' putaran perbaikan): '.$note.$range),
        ])->save();

        if (($run->mode ?: config('execution.mode', 'worktree')) === 'direct') {
            $run->forceFill(['status' => 'COMPLETED', 'completed_at' => now()])->save();

            return;
        }

        $run->forceFill(['status' => 'MERGING'])->save();
        \App\Jobs\MergeExecutionJob::dispatch($run->id);
    }
}
