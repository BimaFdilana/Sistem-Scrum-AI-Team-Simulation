<?php

namespace App\Console\Commands;

use App\Models\ExecutionRun;
use App\Models\Story;
use Illuminate\Console\Command;

/**
 * Data metrik untuk laporan/skripsi: velocity per sprint, jumlah putaran dan
 * revisi QA, temuan, serta waktu per story. Hanya membaca database.
 */
class ExecutionMetrics extends Command
{
    protected $signature = 'execution:metrics {--format=table : table|csv|json}';

    protected $description = 'Metrik eksekusi per story dan velocity per sprint (untuk laporan)';

    public function handle(): int
    {
        $stories = Story::query()
            ->with(['sprint', 'executionRuns.tasks', 'executionRuns.findings'])
            ->whereHas('executionRuns')
            ->orderBy('id')
            ->get();

        $rows = $stories->map(fn (Story $story) => $this->row($story))->values();
        $velocity = $rows->where('diterima', true)
            ->groupBy('sprint')
            ->map(fn ($group, $sprint) => ['sprint' => $sprint, 'story_diterima' => $group->count(), 'velocity_poin' => (int) $group->sum('poin')])
            ->values();

        match ($this->option('format')) {
            'json' => $this->line(json_encode(['story' => $rows, 'velocity' => $velocity], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)),
            'csv' => $this->csv($rows->all()),
            default => $this->renderTable($rows, $velocity),
        };

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function row(Story $story): array
    {
        $runs = $story->executionRuns;
        $tasks = $runs->flatMap->tasks;
        $findings = $runs->flatMap->findings;
        $first = $runs->min('created_at');
        $last = $runs->whereNotNull('completed_at')->max('completed_at');

        return [
            'story' => $story->display_id.' '.$story->effectiveTitle(),
            'sprint' => $story->sprint?->name ?? '-',
            'poin' => (int) $story->estimate_points,
            'diterima' => $story->acceptance_status === 'BERHASIL',
            'run' => $runs->count(),
            'putaran_qa' => (int) $runs->sum('qa_rounds'),
            'revisi_task' => (int) $tasks->sum('revision_count'),
            'temuan' => $findings->count(),
            'temuan_ditutup_sm' => $findings->where('status', 'DISMISSED')->count(),
            'berhenti_di_po' => $runs->where('error_code', 'WATCHDOG_STALLED')->count() + $runs->filter(fn (ExecutionRun $r) => str_starts_with((string) $r->error_code, 'QA_'))->count(),
            'menit' => $first && $last ? (int) round($first->diffInSeconds($last) / 60) : null,
        ];
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function csv(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $this->line(implode(',', array_keys($rows[0])));
        foreach ($rows as $row) {
            $this->line(implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', (string) (is_bool($v) ? ($v ? 'ya' : 'tidak') : $v)).'"', $row)));
        }
    }

    private function renderTable($rows, $velocity): void
    {
        if ($rows->isEmpty()) {
            $this->info('Belum ada story yang dieksekusi.');

            return;
        }
        $this->table(array_keys($rows->first()), $rows->map(fn ($r) => array_map(fn ($v) => is_bool($v) ? ($v ? 'ya' : 'tidak') : $v, $r))->all());
        $this->newLine();
        $this->info('Velocity per sprint (story diterima):');
        $this->table(['sprint', 'story_diterima', 'velocity_poin'], $velocity->all());
    }
}
