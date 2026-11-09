<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bandingkan hasil eksekusi per pelaksana (hermes vs opencode vs internal).
 *
 * Yang diukur: jumlah task, laju lolos QA pada percobaan pertama, rata-rata
 * revisi, durasi rata-rata per task, dan jumlah kegagalan. Tujuannya supaya
 * keputusan "pakai OpenCode atau tidak" berbasis angka, bukan kesan.
 */
class CompareExecutionDrivers extends Command
{
    protected $signature = 'reports:execution-compare {--run= : Hanya satu run id}';

    protected $description = 'Bandingkan hasil task per executor (hermes/opencode/internal)';

    public function handle(): int
    {
        $query = DB::table('execution_tasks')
            ->join('execution_runs', 'execution_runs.id', '=', 'execution_tasks.execution_run_id')
            ->select([
                'execution_tasks.id',
                'execution_tasks.executor',
                'execution_tasks.status',
                'execution_tasks.revision_count',
                'execution_tasks.created_at',
                'execution_tasks.updated_at',
                'execution_tasks.execution_run_id',
            ]);

        if ($this->option('run') !== null) {
            $query->where('execution_tasks.execution_run_id', (int) $this->option('run'));
        }

        $tasks = $query->get();

        // Ringkasan mode & putaran QA per run (alur baru: direct + batch).
        $runs = DB::table('execution_runs')
            ->when($this->option('run') !== null, fn ($q) => $q->where('id', (int) $this->option('run')))
            ->orderBy('id')
            ->get(['id', 'mode', 'qa_mode', 'qa_rounds', 'status']);
        foreach ($runs as $r) {
            $this->line(sprintf(
                'run #%d: mode=%s qa=%s putaran=%d status=%s',
                $r->id, $r->mode ?? '-', $r->qa_mode ?? '-', (int) ($r->qa_rounds ?? 0), $r->status
            ));
        }
        $this->newLine();

        if ($tasks->isEmpty()) {
            $this->warn('Belum ada task untuk dibandingkan.');

            return self::SUCCESS;
        }

        $groups = $tasks->groupBy(fn ($task) => $task->executor ?: 'belum-tercatat');

        $rows = [];
        foreach ($groups as $executor => $items) {
            $total = $items->count();
            $approved = $items->where('status', 'APPROVED')->count();
            $failed = $items->whereIn('status', ['FAILED', 'BLOCKED'])->count();
            $firstPass = $items->where('status', 'APPROVED')->where('revision_count', 0)->count();

            $durations = $items->map(function ($task) {
                if (! $task->created_at || ! $task->updated_at) {
                    return null;
                }

                return (strtotime((string) $task->updated_at) - strtotime((string) $task->created_at)) / 60;
            })->filter(fn ($minutes) => $minutes !== null && $minutes >= 0);

            $rows[] = [
                'executor' => $executor,
                'task' => $total,
                'lolos_qa' => $approved,
                'lolos_tanpa_revisi' => $firstPass,
                'gagal_blokir' => $failed,
                'rata_revisi' => $total > 0 ? round($items->avg('revision_count'), 2) : 0,
                'rata_menit' => $durations->isEmpty() ? '-' : round($durations->avg(), 1),
            ];
        }

        $this->table(
            ['executor', 'task', 'lolos QA', 'lolos tanpa revisi', 'gagal/blokir', 'rata revisi', 'rata menit'],
            $rows
        );

        $this->line('');
        $this->line('Catatan: "lolos tanpa revisi" = APPROVED dengan revision_count 0 (QA setuju pada percobaan pertama).');

        return self::SUCCESS;
    }
}
