<?php

namespace App\Jobs;

use App\Models\ExecutionRun;
use App\Models\ExecutionTask;
use App\Models\Notification;
use App\Services\KanbanClient;
use App\Support\Workspace\WorktreeManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Penunggu kartu kanban Hermes untuk satu execution task.
 *
 * Alur: ExecuteExecutionTaskJob (jalur kanban) sudah membuat worktree,
 * board project, dan kartu todo, lalu mendelegasikan ke job ini. Job ini
 * TIDAK mengerjakan code — ia hanya mengecek status kartu tiap 60 detik:
 *   - done    -> ambil diff worktree, commit, IN_REVIEW, dispatch QA
 *                (gerbang lama, sama persis dengan jalur Hermes lama).
 *   - blocked -> failRun, dengan alasan kartu.
 *   - todo/ready/running/review -> release(60), tunggu lagi.
 *   - archived -> failRun (kartu dihapus dari antrean sebelum selesai).
 *
 * Batas: 120 percobaan x 60 detik = sekitar 2 jam menunggu worker.
 * Bila batas habis, failed() menandai task FAILED agar run tidak
 * menggantung selamanya di IN_PROGRESS.
 *
 * Dokumentasi: id kartu, board, dan status terakhir tersimpan di pesan
 * eksekusi (metadata), sehingga riwayat multi-agent bisa ditelusuri
 * dari halaman eksekusi tanpa membuka database kanban Hermes.
 */
class KanbanExecutionPollJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 120;

    public int $timeout = 120;

    public function __construct(
        public readonly int $runId,
        public readonly int $taskId,
    ) {}

    public function handle(KanbanClient $kanban, WorktreeManager $worktrees): void
    {
        $run = ExecutionRun::query()->find($this->runId);
        $task = ExecutionTask::query()->find($this->taskId);
        if (! $run || ! $task || $task->execution_run_id !== $run->id
            || $task->status !== 'IN_PROGRESS'
            || ! in_array($run->status, ['EXECUTING', 'REVIEWING'], true)) {
            return;
        }
        $project = $run->project();
        $baseline = $project?->workspace_path;
        $worktree = $task->worktree_path;
        $branch = $task->branch;
        if (! $baseline || ! is_dir($baseline) || ! $worktree || ! $branch) {
            $this->failRun($run, $task, 'KANBAN_POLL_FAILED', $worktrees, $baseline, 'Worktree/branch task tidak ditemukan saat menunggu kartu kanban.');

            return;
        }
        $card = $this->kanbanCard($run, $task);
        if ($card === null) {
            $this->failRun($run, $task, 'KANBAN_POLL_FAILED', $worktrees, $baseline, 'Id kartu kanban tidak ditemukan di pesan eksekusi.');

            return;
        }
        try {
            $result = $kanban->showTask($card['board'], $card['id']);
        } catch (Throwable $exception) {
            // Gangguan baca status sementara: tunggu lagi, jangan gagalkan
            // run karena satu kali CLI gagal — kecuali sudah di ujung tries.
            if ($this->attempts() < $this->tries) {
                $this->release(60);

                return;
            }
            throw $exception;
        }
        $status = $result['status'];
        if (in_array($status, ['todo', 'ready', 'running', 'review'], true)) {
            $this->release(60);

            return;
        }
        if ($status === 'blocked') {
            // Alasan blokir dari worker ada di beberapa tempat tergantung
            // versi CLI: task.blocked_reason, latest_summary, atau summary
            // run terakhir. Dulu hanya dua kunci pertama yang dibaca
            // sehingga PO melihat pesan generik tanpa sebab.
            $runs = $result['raw']['runs'] ?? [];
            $lastRun = is_array($runs) && $runs !== [] ? end($runs) : [];
            $reason = $result['raw']['task']['blocked_reason']
                ?? $result['raw']['blocked_reason']
                ?? $result['raw']['latest_summary']
                ?? (is_array($lastRun) ? ($lastRun['summary'] ?? $lastRun['error'] ?? null) : null)
                ?? $result['raw']['last_failure_error']
                ?? $result['raw']['result']
                ?? 'Kartu kanban diblokir oleh worker/dispatcher.';
            $this->failRun($run, $task, 'KANBAN_BLOCKED', $worktrees, $baseline, is_string($reason) ? mb_substr($reason, 0, 1000) : 'Kartu kanban diblokir.');

            return;
        }
        if ($status !== 'done') {
            // archived dan status lain yang tidak dikenal: jangan digantung.
            $this->failRun($run, $task, 'KANBAN_POLL_FAILED', $worktrees, $baseline, 'Status kartu kanban tidak bisa dilanjutkan: '.$status);

            return;
        }

        // Worker menandai done — verifikasi dari sisi Laravel, bukan
        // percaya begitu saja: tanpa perubahan file, ini kegagalan
        // no-op yang sama seperti jalur Hermes lama, dan harus gagal
        // bersih, bukan masuk QA dengan diff kosong.
        $files = $this->changedFiles($worktree);
        if ($files === []) {
            $this->failRun($run, $task, 'KANBAN_NO_CHANGES', $worktrees, $baseline, 'Worker kanban menandai selesai tanpa mengubah file apa pun di worktree.');

            return;
        }
        $summary = $result['raw']['result'] ?? $result['raw']['summary'] ?? 'Worker kanban selesai.';
        $summary = is_string($summary) ? mb_substr($summary, 0, 2000) : 'Worker kanban selesai.';

        $worktrees->commit($worktree, 'Task #'.$task->id.': '.$task->title);
        $run->messages()->create([
            'execution_task_id' => $task->id,
            'sender_slug' => $task->assignee,
            'recipient_slug' => 'qa-engineer',
            'content' => $summary.' ('.count($files).' file: '.implode(', ', array_slice($files, 0, 5)).(count($files) > 5 ? ', ...' : '').')',
            'metadata' => ['files' => $files, 'revision' => $task->revision_count, 'kanban_board' => $card['board'], 'kanban_task_id' => $card['id'], 'kanban_status' => 'done'],
        ]);
        $task->forceFill(['status' => 'IN_REVIEW', 'diff_snapshot' => $worktrees->diff($baseline, $branch, $task->chain_base ?: 'HEAD')])->save();
        $run->forceFill(['status' => 'REVIEWING'])->save();
        ReviewExecutionTaskJob::dispatch($run->id, $task->id);
    }

    /** Job kehabisan tries: jangan biarkan task menggantung IN_PROGRESS. */
    public function failed(Throwable $exception): void
    {
        $run = ExecutionRun::query()->find($this->runId);
        $task = ExecutionTask::query()->find($this->taskId);
        if (! $run || ! $task || $task->status !== 'IN_PROGRESS') {
            return;
        }
        $project = $run->project();
        $this->failRun($run, $task, 'KANBAN_POLL_FAILED', app(WorktreeManager::class), $project?->workspace_path, 'Menunggu kartu kanban habis (timeout ~2 jam): '.$exception->getMessage());
    }

    /** @return array{board: string, id: string}|null */
    private function kanbanCard(ExecutionRun $run, ExecutionTask $task): ?array
    {
        $messages = $run->messages()
            ->where('execution_task_id', $task->id)
            ->orderBy('id', 'desc')
            ->get();
        foreach ($messages as $message) {
            $metadata = $message->metadata ?? [];
            if (is_array($metadata) && is_string($metadata['kanban_task_id'] ?? null) && ($metadata['kanban_task_id'] ?? '') !== ''
                && is_string($metadata['kanban_board'] ?? null) && ($metadata['kanban_board'] ?? '') !== '') {
                return ['board' => $metadata['kanban_board'], 'id' => $metadata['kanban_task_id']];
            }
        }

        return null;
    }

    /** @return string[] */
    private function changedFiles(string $worktree): array
    {
        $process = new Process(['git', '-C', $worktree, 'status', '--porcelain'], $worktree, null, null, 60);
        $process->run();
        if (! $process->isSuccessful()) {
            // Sertakan stderr: tanpa ini penyebab nyata (mis. registrasi
            // worktree hilang / "dubious ownership") tidak terlihat di log.
            $reason = trim($process->getErrorOutput()) ?: trim($process->getOutput());

            throw new RuntimeException('Gagal membaca status git worktree: '.mb_substr($reason !== '' ? $reason : 'tanpa pesan git', 0, 500));
        }
        $files = [];
        foreach (explode("\n", $process->getOutput()) as $line) {
            if ($line === '') {
                continue;
            }
            $path = trim(substr($line, 3));
            if (str_contains($path, ' -> ')) {
                $path = trim(substr($path, (int) strrpos($path, ' -> ') + 4));
            }
            $path = trim($path, '"');
            if ($path !== '' && ! str_contains($path, '..')) {
                $files[] = $path;
            }
        }

        return array_values(array_unique($files));
    }

    private function failRun(ExecutionRun $run, ExecutionTask $task, string $code, ?WorktreeManager $worktrees = null, ?string $baseline = null, ?string $errorMessage = null): void
    {
        $task->forceFill(['status' => 'FAILED', 'last_error' => $errorMessage ?: $code])->save();
        $run->forceFill(['status' => 'FAILED', 'error_code' => $code, 'completed_at' => now()])->save();
        try {
            Notification::patchFailed($run, $task, $errorMessage ?: $code);
        } catch (Throwable) {
            // Best-effort, sama seperti failRun di job eksekusi.
        }
        if ($worktrees !== null && $baseline !== null) {
            try {
                $worktrees->cleanupTaskWorktrees($baseline, $run->id, $task->id);
            } catch (Throwable) {
                // Best-effort.
            }
        }
    }
}
