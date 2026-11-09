<?php

namespace App\Jobs;

use App\Models\ExecutionRun;
use App\Models\ExecutionTask;
use App\Models\Project;
use App\Services\AgentProfileClient;
use App\Support\Execution\BatchQa;
use App\Support\Execution\FindingDialogue;
use App\Support\Execution\ProjectFacts;
use App\Support\Execution\RuntimeSmoke;
use App\Support\Execution\TaskCompletion;
use App\Support\Execution\TaskFileScope;
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
        ?FindingDialogue $dialogue = null,
    ): void {
        $dialogue ??= app(FindingDialogue::class);
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
            if (TaskCompletion::isNoChange($run, $task)) {
                // Tidak ada diff: sertakan isi file target agar QA bisa memverifikasi sendiri.
                $diffParts[] = '## Task #'.$task->id.' ('.$task->assignee.'): '.$task->title."\nTANPA PERUBAHAN: agen menyatakan task sudah terpenuhi oleh kode yang ada. Periksa isi file target pada bagian 'Isi file' sebelum menyetujui; tolak bila kriteria sebenarnya belum terpenuhi.";
                foreach ((new TaskFileScope)->filesFor($run, $task) as $file) {
                    $changed[$file] = true;
                }

                continue;
            }
            $diffParts[] = '## Task #'.$task->id.' ('.$task->assignee.'): '.$task->title."\n".Str::limit($diff, 6000);
            foreach ($worktrees->changedFiles($baseline, $task->branch, $task->chain_base ?: 'HEAD') as $file) {
                $changed[$file] = true;
            }
        }

        $fileContext = $this->fileContext($baseline, array_keys($changed));
        $criteria = $tasks->map(fn (ExecutionTask $t) => '#'.$t->id.' '.$t->title.' — '.$t->description)->implode("\n\n");

        // Uji jalan nyata lebih dulu: kode yang crash tidak perlu dinilai QA, langsung
        // dikembalikan ke developer dengan pesan error aslinya sebagai temuan.
        $smoke = $this->smoke($run, $project, array_keys($changed));
        $smokeFindings = $smoke !== null ? app(RuntimeSmoke::class)->findings($smoke, array_keys($changed)) : [];
        $fromSmoke = $smokeFindings !== [];
        if ($smoke !== null && $smoke['ran']) {
            // Hasil uji jalan terbaru menggantikan temuan uji jalan putaran sebelumnya.
            $dialogue->resolveSmoke($run);
        }

        // Tidak ada crash: bandingkan dengan kode sebelum run ini. Halaman/form yang dulu
        // berhasil lalu kini gagal adalah regresi, dikembalikan ke developer sebelum QA.
        $regressionFindings = [];
        if (! $fromSmoke && $smoke !== null && $smoke['ran']) {
            $baseSmoke = $this->baseSmoke($run, $project, array_keys($changed));
            if ($baseSmoke !== null && $baseSmoke['ran']) {
                $regressionFindings = app(RuntimeSmoke::class)->regressions($baseSmoke, $smoke);
            }
        }

        if ($regressionFindings !== []) {
            $run->messages()->create([
                'sender_slug' => 'qa-engineer',
                'recipient_slug' => 'team',
                'content' => 'Uji regresi menemukan '.count($regressionFindings).' perilaku lama yang rusak; kode dikembalikan ke developer sebelum dinilai QA.'."\n"
                    .collect($regressionFindings)->map(fn (array $f) => '- '.$f['instruction'])->implode("\n"),
                'metadata' => ['batch_qa' => true, 'smoke' => true, 'regression' => true, 'approved' => false],
            ]);
            $review = ['approved' => false, 'feedback' => '', 'findings' => $regressionFindings, 'resolved' => []];
        } elseif ($fromSmoke) {
            $run->messages()->create([
                'sender_slug' => 'qa-engineer',
                'recipient_slug' => 'team',
                'content' => 'Uji jalan menemukan '.count($smokeFindings).' error; kode dikembalikan ke developer sebelum dinilai QA.'."\n".app(RuntimeSmoke::class)->summary($smoke),
                'metadata' => ['batch_qa' => true, 'smoke' => true, 'approved' => false],
            ]);
            $review = ['approved' => false, 'feedback' => '', 'findings' => $smokeFindings, 'resolved' => []];
        } else {
            $smokeLine = $smoke !== null ? app(RuntimeSmoke::class)->summary($smoke) : 'Uji jalan nyata: tidak diaktifkan.';

            $prompt = implode("\n\n", [
                'Kamu QA Engineer. Nilai GABUNGAN perubahan seluruh task BE dan FE pada satu run.',
                'Kembalikan JSON tepat: {"approved":true,"feedback":"...","criteria":[{"criterion":"teks kriteria","met":true,"evidence":"file/perilaku yang membuktikan"}]} bila SEMUA acceptance criteria story terpenuhi (satu entri criteria per kriteria, semuanya met:true dengan bukti), atau {"approved":false,"findings":[{"file":"path","instruction":"perbaikan konkret","verify":"cara memeriksa"}]} bila ada yang harus diperbaiki.',
                'Acceptance criteria story (SUMBER KEBENARAN penilaian):',
                $run->subjectCriteria(),
                $dialogue->open($run)->isEmpty() ? '' : "Temuan putaran sebelumnya yang BELUM ditutup. Periksa ulang HANYA ini (dan regresi); tulis id yang sudah beres di \"resolved\":[id,...] pada JSON.\n".$dialogue->feedbackFor($dialogue->open($run)),
                'Aturan: nilai hanya perubahan yang ada di diff/file; jangan menuntut elemen di luar diff; setiap temuan WAJIB menyebut file yang benar-benar berubah; instruksi harus spesifik dan bisa langsung dikerjakan; jangan mengulang temuan yang sama pada putaran berikutnya. '
                    .'Cocokkan nama tabel, kolom, dan rute di kode dengan FAKTA PROJECT; nama yang tidak ada di sana (dan tidak dibuat migrasi/rute di diff ini) adalah temuan. '
                    .'Hasil uji jalan di bawah adalah bukti nyata: halaman VALIDASI berarti form ditolak dengan data contoh — periksa apakah aturannya wajar.',
                'Kriteria seluruh task:',
                $criteria,
                'Hasil gerbang tes: LULUS',
                $smokeLine,
                $project !== null ? $this->projectFacts($project) : '',
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
        }

        if ($review['approved']) {
            $dialogue->resolveAllOpen($run);
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

        // QA yang memeriksa ulang boleh menutup temuan lama dan menambah temuan baru.
        $dialogue->resolve($run, $review['resolved']);
        $nextRound = (int) $run->qa_rounds + 1;
        $records = $dialogue->record($run, $findings, $nextRound, $batchQa, $tasks);

        // Review final: perbaikan sudah dijatah habis. Jangan setujui diam-diam
        // dan jangan perbaiki tanpa batas: serahkan ke PO beserta argumen kedua pihak.
        if ((int) $run->qa_rounds >= (int) config('execution.qa_max_repair_rounds', 3)) {
            $summary = $dialogue->summaryForPo($run);
            $run->messages()->create([
                'sender_slug' => 'qa-engineer',
                'recipient_slug' => 'product-owner',
                'content' => 'QA masih menolak setelah '.(int) $run->qa_rounds." putaran perbaikan. Temuan terbuka dan argumen kedua pihak:\n".$summary,
                'metadata' => ['batch_qa' => true, 'approved' => false, 'final' => true],
            ]);
            $this->handOver($run, 'QA_REJECTED_AFTER_REPAIR', 'QA masih menolak setelah '.(int) $run->qa_rounds.' putaran perbaikan; task tidak disetujui otomatis. Temuan: '.Str::limit(str_replace("\n", ' ', $summary), 500));

            return;
        }

        $run->forceFill(['qa_rounds' => $nextRound])->save();

        // Developer menjawab tiap temuan; SM menengahi yang diperdebatkan.
        // Error uji jalan adalah fakta (pesan exception asli), bukan pendapat: tidak dinegosiasikan.
        if (! $fromSmoke) {
            $dialogue->negotiate($run, $records, $agents);
        }
        $open = $dialogue->open($run);

        if ($open->isEmpty()) {
            // Penengah SM memenangkan developer atas semua temuan baru dan tidak ada
            // temuan lama yang tersisa: jejaknya ada di pesan penengah dan tabel temuan.
            $batchQa->finalize($run, 'Semua temuan QA ditutup oleh penengah SM (developer dinyatakan benar); lihat daftar temuan.');

            return;
        }

        // Tiap temuan sudah punya task tujuan (yang cakupan filenya memuat file temuan);
        // kelompokkan per task, bukan per peran, supaya perbaikan sampai ke task yang tepat.
        $targets = [];
        foreach ($open->groupBy('execution_task_id') as $taskId => $items) {
            $target = $tasks->firstWhere('id', (int) $taskId) ?? $tasks->firstWhere('assignee', $items->first()->assignee) ?? $tasks->first();
            $feedback = $dialogue->feedbackFor($items);
            $target->forceFill([
                'status' => 'REVISION',
                'qa_feedback' => 'Perbaikan dari QA (putaran '.(int) $run->qa_rounds."):\n".$feedback,
                'revision_count' => (int) $target->revision_count + 1,
            ])->save();
            $targets[$target->id] = $target;

            $run->messages()->create([
                'execution_task_id' => $target->id,
                'sender_slug' => 'qa-engineer',
                'recipient_slug' => $target->assignee,
                'content' => 'QA meminta perbaikan pada '.$items->count().' temuan untuk task "'.$target->title.'": '.$feedback,
                'metadata' => ['batch_qa' => true, 'approved' => false, 'round' => (int) $run->qa_rounds],
            ]);
        }

        // Task lain tetap menunggu keputusan akhir.
        $run->forceFill(['status' => 'EXECUTING', 'qa_started_at' => null])->save();

        foreach ($targets as $target) {
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
                'line' => isset($item['line']) && is_numeric($item['line']) ? (int) $item['line'] : null,
                'severity' => is_string($item['severity'] ?? null) ? $item['severity'] : null,
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
            'resolved' => array_values(array_filter(array_map('intval', (array) ($data['resolved'] ?? [])), fn (int $id) => $id > 0)),
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

    /**
     * Uji jalan untuk file yang berubah; null bila dimatikan.
     *
     * @param  array<int, string>  $changedFiles
     * @return array{ran: bool, error: ?string, roles: array<int, ?string>, results: array<int, array<string, mixed>>}|null
     */
    private function smoke(ExecutionRun $run, ?Project $project, array $changedFiles): ?array
    {
        if ($project === null || ! config('execution.smoke_enabled', true)) {
            return null;
        }

        try {
            return app(RuntimeSmoke::class)->run($project, $changedFiles);
        } catch (\Throwable $exception) {
            Log::warning('Uji jalan gagal', ['run' => $run->id, 'error' => $exception->getMessage()]);

            return ['ran' => false, 'error' => Str::limit($exception->getMessage(), 300), 'roles' => [], 'results' => []];
        }
    }

    /**
     * Uji jalan kode sebelum run ini (titik awal run) untuk pembanding regresi.
     * Hanya mode direct: di sana titik awal tersimpan sebagai ref git.
     *
     * @param  array<int, string>  $changedFiles
     * @return array{ran: bool, error: ?string, roles: array<int, ?string>, results: array<int, array<string, mixed>>}|null
     */
    private function baseSmoke(ExecutionRun $run, ?Project $project, array $changedFiles): ?array
    {
        if ($project === null || ($run->mode ?: config('execution.mode', 'worktree')) !== 'direct') {
            return null;
        }

        $start = 'refs/scrumspace/run-'.$run->id.'-start';

        try {
            if (! app(WorktreeManager::class)->branchExists((string) $project->workspace_path, $start)) {
                return null;
            }

            return app(RuntimeSmoke::class)->runAtRef($project, $start, $changedFiles);
        } catch (\Throwable $exception) {
            Log::warning('Uji regresi gagal', ['run' => $run->id, 'error' => $exception->getMessage()]);

            return null;
        }
    }

    private function projectFacts(Project $project): string
    {
        try {
            return app(ProjectFacts::class)->section($project);
        } catch (\Throwable) {
            return '';
        }
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
