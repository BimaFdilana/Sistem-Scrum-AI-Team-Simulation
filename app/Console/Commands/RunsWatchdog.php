<?php

namespace App\Console\Commands;

use App\Jobs\ExecuteExecutionTaskJob;
use App\Jobs\ReviewExecutionTaskJob;
use App\Models\ExecutionRun;
use App\Models\ExecutionTask;
use App\Support\Execution\ExecutionScheduler;
use Illuminate\Console\Command;

/**
 * Selamatkan run yang menggantung (worker mati di tengah jalan).
 *
 * Aturan: tidak ada yang boleh menggantung diam-diam.
 *  1. Task macet dijalankan ulang otomatis, maksimal MAX_AUTO_RESUME kali.
 *  2. Lewat batas itu task BLOCKED dan run pindah ke NEEDS_PO: berhenti
 *     dengan alasan yang terbaca, menunggu keputusan PO (Lanjutkan / Gagal).
 *  3. Run yang tidak punya task berjalan tetapi masih punya task menunggu
 *     dicoba dikirim ulang; bila tak ada yang bisa dikirim -> NEEDS_PO.
 */
class RunsWatchdog extends Command
{
    /** Berapa kali sebuah task dijalankan ulang otomatis sebelum meminta PO. */
    public const MAX_AUTO_RESUME = 2;

    protected $signature = 'runs:watchdog {--minutes=20} {--dry-run}';

    protected $description = 'Jalankan ulang task macet, atau hentikan run ke NEEDS_PO bila tetap macet';

    public function handle(ExecutionScheduler $scheduler): int
    {
        $minutes = max(5, (int) $this->option('minutes'));
        $dry = (bool) $this->option('dry-run');
        $cutoff = now()->subMinutes($minutes);
        $changed = 0;

        $stalled = ExecutionTask::query()
            ->whereIn('status', ['IN_PROGRESS', 'IN_REVIEW'])
            ->where(function ($q) use ($cutoff) {
                $q->where('last_progress_at', '<', $cutoff)->orWhereNull('last_progress_at');
            })
            ->with('run')
            ->get();

        foreach ($stalled as $task) {
            $run = $task->run;
            $this->line('task #'.$task->id.' ('.$task->status.') diam > '.$minutes.' menit');
            if ($dry || ! $run || ! in_array($run->status, ['EXECUTING', 'REVIEWING', 'QA_REVIEW'], true)) {
                continue;
            }

            if ((int) $task->stall_count >= self::MAX_AUTO_RESUME) {
                $this->handOverToPo($run, $task, 'Task "'.$task->title.'" macet '.($task->stall_count + 1).' kali berturut-turut.');
                $changed++;

                continue;
            }

            $wasReview = $task->status === 'IN_REVIEW';
            $task->forceFill([
                'stall_count' => $task->stall_count + 1,
                'status' => $wasReview ? 'IN_REVIEW' : 'PENDING',
                'last_error' => 'WATCHDOG_STALLED',
                'last_progress_at' => now(),
            ])->save();

            $run->messages()->create([
                'execution_task_id' => $task->id,
                'sender_slug' => 'scrum-master',
                'recipient_slug' => $task->assignee ?: 'product-owner',
                'content' => 'Watchdog: task "'.$task->title.'" tidak ada progres > '.$minutes.' menit; dijalankan ulang otomatis ('.$task->stall_count.'/'.self::MAX_AUTO_RESUME.').',
            ]);

            if ($wasReview) {
                ReviewExecutionTaskJob::dispatch($run->id, $task->id);
            } else {
                ExecuteExecutionTaskJob::dispatch($run->id, $task->id);
            }
            $changed++;
        }

        $runs = ExecutionRun::query()
            ->whereIn('status', ['EXECUTING', 'REVIEWING', 'QA_REVIEW'])
            ->where('updated_at', '<', now()->subMinutes($minutes * 2))
            ->get();

        foreach ($runs as $run) {
            if ($run->tasks()->whereIn('status', ['IN_PROGRESS', 'IN_REVIEW'])->exists()) {
                continue;
            }
            $waiting = $run->tasks()->whereIn('status', ['PENDING', 'REVISION'])->count();
            if ($waiting === 0) {
                continue;
            }

            $this->line('run #'.$run->id.' stagnan ('.$waiting.' task menunggu)');
            if ($dry) {
                continue;
            }

            // Task REVISION tidak pernah dikirim scheduler: kembalikan ke PENDING.
            $run->tasks()->where('status', 'REVISION')->update(['status' => 'PENDING']);
            $run->touch();

            if ($scheduler->dispatchReady($run) !== []) {
                $changed++;

                continue;
            }

            $this->handOverToPo($run, null, $waiting.' task menunggu tetapi tidak ada yang bisa dijalankan (dependensi/benturan file tidak terselesaikan).');
            $changed++;
        }

        $this->info(($dry ? '[dry-run] ' : '').$changed.' item ditangani.');

        return self::SUCCESS;
    }

    private function handOverToPo(ExecutionRun $run, ?ExecutionTask $task, string $reason): void
    {
        $moved = $run->transitionTo(ExecutionRun::NEEDS_PO, ['EXECUTING', 'REVIEWING', 'QA_REVIEW'], [
            'error_code' => 'WATCHDOG_STALLED',
            'summary' => $reason.' Run dihentikan agar tidak menggantung; Lanjutkan atau tandai Gagal.',
        ]);

        if ($moved && $task !== null) {
            $task->forceFill(['status' => 'BLOCKED', 'last_error' => 'WATCHDOG_STALLED'])->save();
        }
    }
}
