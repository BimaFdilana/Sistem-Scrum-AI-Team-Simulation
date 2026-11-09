<?php

namespace App\Console\Commands;

use App\Models\ExecutionRun;
use App\Models\ExecutionTask;
use App\Support\Workspace\BaselineSnapshot;
use Illuminate\Console\Command;

/**
 * Selamatkan run yang menggantung: worker mati di tengah jalan membuat task
 * tetap IN_PROGRESS tanpa batas.
 */
class RunsWatchdog extends Command
{
    protected $signature = 'runs:watchdog {--minutes=20} {--dry-run}';

    protected $description = 'Tandai task/run yang tidak ada progres sebagai gagal agar bisa ditindak';

    public function handle(BaselineSnapshot $snapshots): int
    {
        $minutes = max(5, (int) $this->option('minutes'));
        $cutoff = now()->subMinutes($minutes);
        $changed = 0;

        $tasks = ExecutionTask::query()
            ->whereIn('status', ['IN_PROGRESS', 'IN_REVIEW'])
            ->where(function ($q) use ($cutoff) {
                $q->where('last_progress_at', '<', $cutoff)->orWhereNull('last_progress_at');
            })
            ->get();

        foreach ($tasks as $task) {
            $this->line('task #'.$task->id.' ('.$task->status.') diam > '.$minutes.' menit');
            if ($this->option('dry-run')) {
                continue;
            }

            $run = $task->run;
            $task->forceFill([
                'status' => 'REVISION',
                'last_error' => 'WATCHDOG_STALLED',
                'qa_feedback' => 'Task dihentikan watchdog: tidak ada progres selama '.$minutes.' menit. Silakan jalankan ulang.',
                'last_progress_at' => now(),
            ])->save();

            $run?->messages()->create([
                'execution_task_id' => $task->id,
                'sender_slug' => 'scrum-master',
                'recipient_slug' => $task->assignee ?: 'product-owner',
                'content' => 'Watchdog: task "'.$task->title.'" tidak ada progres > '.$minutes.' menit; dikembalikan untuk dijalankan ulang.',
            ]);

            $changed++;
        }

        $runs = ExecutionRun::query()
            ->whereIn('status', ['EXECUTING', 'REVIEWING', 'QA_REVIEW'])
            ->where('updated_at', '<', now()->subMinutes($minutes * 2))
            ->get();

        foreach ($runs as $run) {
            $busy = $run->tasks()->whereIn('status', ['IN_PROGRESS', 'IN_REVIEW'])->exists();
            if ($busy) {
                continue;
            }
            $pending = $run->tasks()->whereIn('status', ['PENDING', 'REVISION'])->count();
            if ($pending === 0) {
                continue;
            }

            $this->line('run #'.$run->id.' stagnan ('.$pending.' task menunggu)');
            if ($this->option('dry-run')) {
                continue;
            }

            $run->forceFill([
                'status' => 'BLOCKED',
                'error_code' => 'WATCHDOG_STALLED',
                'summary' => 'Run dihentikan watchdog: tidak ada progres selama '.($minutes * 2).' menit.',
            ])->save();
            $changed++;
        }

        $this->info(($this->option('dry-run') ? '[dry-run] ' : '').$changed.' item ditandai.');

        return self::SUCCESS;
    }
}
