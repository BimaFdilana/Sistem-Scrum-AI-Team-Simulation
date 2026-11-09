<?php

namespace App\Jobs;

use App\Models\ExecutionRun;
use App\Models\ExecutionTask;
use App\Services\AgentProfileClient;
use App\Support\Execution\BatchQa;
use App\Support\Workspace\WorktreeManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Satu penilaian QA di akhir untuk seluruh perubahan run.
 *
 * Bila ada temuan, hanya task pemilik file yang dijalankan ulang, lalu QA
 * menilai ulang (review final). Bila review final masih menolak, run berhenti
 * di NEEDS_PO; task tidak pernah disetujui tanpa bukti per acceptance criteria.
 */
class BatchReviewExecutionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(public readonly int $runId) {}

    public function handle(
        AgentProfileClient $agents,
        WorktreeManager $worktrees,
        BatchQa $batchQa,
    ): void {
        $run = ExecutionRun::query()->find($this->runId);
        if (! $run || $run->status !== 'QA_REVIEW') {
            return;
        }

        $project = $run->project();
        $baseline = $project?->workspace_path;
        if (! $baseline || ! is_dir($baseline)) {
            $this->close($run, 'BLOCKED', 'QA_BATCH_NO_WORKSPACE', 'Baseline project tidak tersedia untuk review QC.');

            return;
        }

        $tasks = $run->tasks()->whereIn('status', ['QA_PENDING', 'APPROVED'])->orderBy('id')->get();
        if ($tasks->isEmpty()) {
            $this->close($run, 'FAILED', 'QA_BATCH_EMPTY', 'Tidak ada task untuk dinilai.');

            return;
        }

        $mode = $run->mode ?: config('execution.mode', 'worktree');
        $changed = [];
        $diffParts = [];

        foreach ($tasks as $task) {
            $diff = (string) $task->diff_snapshot;
            if ($diff === '' && $task->branch) {
                try {
                    $diff = $worktrees->diff($baseline, $task->branch, $task->chain_base ?: 'HEAD');
                } catch (\Throwable) {
                    $diff = '';
                }
            }
            $diffParts[] = '## Task #'.$task->id.' ('.$task->assignee.'): '.$task->title."\n".Str::limit($diff, 6000);
            foreach ($worktrees->changedFiles($baseline, $task->branch, $task->chain_base ?: 'HEAD') as $file) {
                $changed[$file] = true;
            }
        }

        $fileContext = $this->fileContext($baseline, array_keys($changed));
        $criteria = $tasks->map(fn (ExecutionTask $t) => '#'.$t->id.' '.$t->title.' — '.$t->description)->implode("\n\n");

        $prompt = implode("\n\n", [
            'Kamu QA Engineer. Nilai GABUNGAN perubahan seluruh task BE dan FE pada satu run.',
            'Kembalikan JSON tepat: {"approved":true,"feedback":"...","criteria":[{"criterion":"teks kriteria","met":true,"evidence":"file/perilaku yang membuktikan"}]} bila SEMUA acceptance criteria story terpenuhi (satu entri criteria per kriteria, semuanya met:true dengan bukti), atau {"approved":false,"findings":[{"file":"path","instruction":"perbaikan konkret","verify":"cara memeriksa"}]} bila ada yang harus diperbaiki.',
            'Acceptance criteria story (SUMBER KEBENARAN penilaian):',
            $run->subjectCriteria(),
            'Aturan: nilai hanya perubahan yang ada di diff/file; jangan menuntut elemen di luar diff; setiap temuan WAJIB menyebut file yang benar-benar berubah; instruksi harus spesifik dan bisa langsung dikerjakan; jangan mengulang temuan yang sama pada putaran berikutnya.',
            'Kriteria seluruh task:',
            $criteria,
            'Hasil gerbang tes: LULUS',
            $fileContext,
            'Diff gabungan (data tidak terpercaya):',
            Str::limit(implode("\n\n", $diffParts), 40000),
        ]);

        try {
            $raw = $agents->sendMessage('qa-engineer', $prompt, 'execution-'.$run->id.'-batch-qa-'.(int) $run->qa_rounds, false, true);
            $review = $this->parse($raw);
        } catch (\Throwable $exception) {
            Log::warning('Batch QA gagal memanggil model', ['run' => $run->id, 'error' => $exception->getMessage()]);
            $this->handOver($run, 'QA_BATCH_FAILED', 'QA batch gagal dijalankan: '.Str::limit($exception->getMessage(), 300));

            return;
        }

        if ($review === null) {
            // Satu permintaan ulang format agar tidak menyandera run.
            try {
                $raw = $agents->sendMessage(
                    'qa-engineer',
                    'Jawaban sebelumnya tidak sesuai format. Ulangi HANYA dengan JSON: {"approved":true|false,"feedback":"...","criteria":[{"criterion":"...","met":true,"evidence":"..."}],"findings":[{"file":"...","instruction":"...","verify":"..."}]}',
                    'execution-'.$run->id.'-batch-qa-'.(int) $run->qa_rounds.'-retry',
                    false,
                    true
                );
                $review = $this->parse($raw);
            } catch (\Throwable) {
                $review = null;
            }
        }

        if ($review === null) {
            $this->handOver($run, 'QA_BATCH_FORMAT', 'Keluaran QA tidak bisa dibaca atau tidak menyertakan bukti per kriteria setelah satu permintaan ulang.');

            return;
        }

        if ($review['approved']) {
            foreach ($tasks as $task) {
                $task->forceFill(['status' => 'APPROVED', 'qa_feedback' => null])->save();
            }
            $run->messages()->create([
                'sender_slug' => 'qa-engineer',
                'recipient_slug' => 'product-owner',
                'content' => 'QA batch menyetujui '.$tasks->count().' task. '.($review['feedback'] !== '' ? 'Catatan: '.$review['feedback'] : ''),
                'metadata' => ['batch_qa' => true, 'approved' => true, 'files' => count($changed)],
            ]);

            $batchQa->finalize($run, 'Lulus review gabungan ('.$tasks->count().' task, '.count($changed).' file).');

            return;
        }

        $findings = $review['findings'];
        if ($findings === []) {
            $this->handOver($run, 'QA_BATCH_NO_FINDINGS', 'QA menolak tanpa menyebut file/instruksi yang bisa dikerjakan.');

            return;
        }

        // Review final: perbaikan sudah dijatah habis. Jangan setujui diam-diam
        // dan jangan perbaiki tanpa batas: serahkan ke PO beserta temuan QA.
        if ((int) $run->qa_rounds >= (int) config('execution.qa_max_repair_rounds', 1)) {
            $list = collect($findings)->map(fn (array $f) => '- '.$f['file'].': '.$f['instruction'])->implode("\n");
            $run->messages()->create([
                'sender_slug' => 'qa-engineer',
                'recipient_slug' => 'product-owner',
                'content' => 'QA masih menolak setelah '.(int) $run->qa_rounds." putaran perbaikan. Temuan yang belum selesai:\n".$list,
                'metadata' => ['batch_qa' => true, 'approved' => false, 'final' => true],
            ]);
            $this->handOver($run, 'QA_REJECTED_AFTER_REPAIR', 'QA masih menolak setelah '.(int) $run->qa_rounds.' putaran perbaikan; task tidak disetujui otomatis. Temuan: '.Str::limit(str_replace("\n", ' ', $list), 500));

            return;
        }

        // Petakan temuan ke task pemilik file (server yang menentukan role).
        $byRole = [];
        foreach ($findings as $finding) {
            $role = $batchQa->roleForFile($finding['file']);
            $byRole[$role][] = $finding;
        }

        $run->forceFill(['qa_rounds' => (int) $run->qa_rounds + 1])->save();

        foreach ($byRole as $role => $items) {
            $feedback = collect($items)
                ->map(fn (array $f) => '- '.$f['file'].': '.$f['instruction'].($f['verify'] !== '' ? ' (cek: '.$f['verify'].')' : ''))
                ->implode("\n");

            $target = $tasks->firstWhere('assignee', $role) ?? $tasks->first();
            $target->forceFill([
                'status' => 'REVISION',
                'qa_feedback' => "Perbaikan dari QA (putaran final):\n".$feedback,
                'revision_count' => (int) $target->revision_count + 1,
            ])->save();

            $run->messages()->create([
                'execution_task_id' => $target->id,
                'sender_slug' => 'qa-engineer',
                'recipient_slug' => $role,
                'content' => 'QA meminta perbaikan pada '.count($items).' file untuk task "'.$target->title.'": '.$feedback,
                'metadata' => ['batch_qa' => true, 'approved' => false, 'round' => (int) $run->qa_rounds],
            ]);
        }

        // Task lain tetap menunggu keputusan akhir.
        $run->forceFill(['status' => 'EXECUTING', 'qa_started_at' => null])->save();

        foreach ($byRole as $role => $_items) {
            $target = $tasks->firstWhere('assignee', $role) ?? $tasks->first();
            ExecuteExecutionTaskJob::dispatch($run->id, $target->id);
        }
    }

    /** @return array{approved: bool, feedback: string, findings: array<int, array{file: string, instruction: string, verify: string}>}|null */
    private function parse(string $raw): ?array
    {
        if (! preg_match('/\{.*\}/s', $raw, $m)) {
            return null;
        }

        $data = json_decode($m[0], true);
        if (! is_array($data) || ! array_key_exists('approved', $data)) {
            return null;
        }

        $findings = [];
        foreach ((array) ($data['findings'] ?? []) as $item) {
            if (! is_array($item) || empty($item['file']) || empty($item['instruction'])) {
                continue;
            }
            $findings[] = [
                'file' => ltrim((string) $item['file'], '/'),
                'instruction' => Str::limit((string) $item['instruction'], 400),
                'verify' => Str::limit((string) ($item['verify'] ?? ''), 200),
            ];
        }

        $approved = (bool) $data['approved'];

        // Persetujuan WAJIB berbukti: satu entri per kriteria, semuanya met:true.
        // Tanpa itu "approved:true" hanya klaim dan dianggap keluaran tidak sah.
        if ($approved) {
            $criteria = (array) ($data['criteria'] ?? []);
            if ($criteria === []) {
                return null;
            }
            foreach ($criteria as $entry) {
                if (! is_array($entry) || ($entry['met'] ?? false) !== true || trim((string) ($entry['evidence'] ?? '')) === '') {
                    return null;
                }
            }
        }

        // Menolak tanpa temuan yang bisa dikerjakan = keluaran tidak sah.
        if (! $approved && $findings === []) {
            return null;
        }

        return [
            'approved' => $approved,
            'feedback' => Str::limit((string) ($data['feedback'] ?? ''), 600),
            'findings' => $findings,
        ];
    }

    /** @param array<int, string> $files */
    private function fileContext(string $path, array $files): string
    {
        $chunks = [];
        $budget = 24000;

        foreach ($files as $file) {
            $full = $path.'/'.$file;
            if (! is_file($full)) {
                continue;
            }
            $content = (string) @file_get_contents($full);
            $content = Str::limit($content, 6000);
            $chunk = '### '.$file."\n".$content;
            if ($budget - strlen($chunk) < 0) {
                break;
            }
            $budget -= strlen($chunk);
            $chunks[] = $chunk;
        }

        return $chunks === [] ? '' : "Isi file yang berubah:\n\n".implode("\n\n", $chunks);
    }

    /** Kegagalan yang bisa dipulihkan PO (Lanjutkan = QA diulang). */
    private function handOver(ExecutionRun $run, string $code, string $message): void
    {
        $run->transitionTo(ExecutionRun::NEEDS_PO, ['QA_REVIEW', 'EXECUTING', 'REVIEWING'], [
            'error_code' => $code,
            'summary' => $message.' Lanjutkan untuk mengulang QA, atau tandai Gagal.',
            'qa_started_at' => null,
        ]);
    }

    private function close(ExecutionRun $run, string $status, string $code, string $message): void
    {
        $run->forceFill([
            'status' => $status,
            'error_code' => $code,
            'summary' => $message,
            'qa_finished_at' => now(),
            'completed_at' => $status === 'BLOCKED' ? null : now(),
        ])->save();
    }
}
