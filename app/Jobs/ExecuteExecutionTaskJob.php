<?php

namespace App\Jobs;

use App\Exceptions\AiUnavailableException;
use App\Models\ExecutionRun;
use App\Models\ExecutionTask;
use App\Models\Notification;
use App\Models\PermissionRequest;
use App\Services\AgentProfileClient;
use App\Services\AgentToolRunner;
use App\Services\AiGateway;
use App\Services\KanbanClient;
use App\Support\Execution\BatchQa;
use App\Support\Execution\DirectWorkspace;
use App\Support\Execution\ExecutorPlanner;
use App\Support\Execution\OpenCodeRunner;
use App\Support\Workspace\PatchApplier;
use App\Support\Workspace\WorktreeManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * FE/BE mengerjakan satu task: agent menghasilkan patch (JSON daftar file),
 * SISTEM yang menerapkannya ke worktree terisolasi (opsi A, keputusan Bima).
 */
class ExecuteExecutionTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 600;

    public function __construct(
        public readonly int $runId,
        public readonly int $taskId,
    ) {
        // Tugas Hermes bisa berjalan jauh lebih lama dari loop lama
        // (simulasi US-01: 248 detik) — beri ruang, tetap dengan batas
        // agar kegagalan tetap tercatat bersih.
        $this->timeout = config('ai.execution_driver') === 'hermes' ? 1800 : 600;
    }

    public function handle(
        WorktreeManager $worktrees,
        AgentToolRunner $runner,
    ): void {
        $run = ExecutionRun::query()->find($this->runId);
        $task = ExecutionTask::query()->find($this->taskId);
        if (! $run || ! $task || $task->execution_run_id !== $run->id
            || ! in_array($task->status, ['PENDING', 'REVISION'], true)
            || ! in_array($run->status, ['EXECUTING', 'REVIEWING'], true)) {
            return;
        }
        $project = $run->project();
        $baseline = $project?->workspace_path;
        if (! $baseline || ! is_dir($baseline)) {
            $this->failRun($run, $task, 'WORKSPACE_MISSING');

            return;
        }

        $originalStatus = $task->status;

        // Pelaksana ditentukan otomatis (lihat ExecutorPlanner): kebijakan run,
        // kill-switch, peran, dan override fallback.
        $executor = app(ExecutorPlanner::class)->forTask($run, $task);
        // Klaim atomik + heartbeat: dua job untuk task yang sama (mis. dikirim ulang
        // watchdog) tidak boleh sama-sama mengerjakannya.
        $claimed = ExecutionTask::query()->whereKey($task->id)
            ->whereIn('status', ['PENDING', 'REVISION'])
            ->update(['status' => 'IN_PROGRESS', 'executor' => $executor, 'last_progress_at' => now()]);
        if ($claimed === 0) {
            return;
        }
        $task->refresh();

        try {
            $worktrees->ensureRepo($baseline);

            // MODE LANGSUNG: agent mengedit baseline, tanpa worktree & merge.
            // Sekuensial (dijaga scheduler) dan memakai commit checkpoint.
            $directMode = ($run->mode ?: config('execution.mode', 'worktree')) === 'direct';

            if ($directMode) {
                $direct = app(DirectWorkspace::class);
                $direct->assertClean($project);
                $direct->beginRun($project, $run);

                $attempt = max(1, (int) $task->attempt_seq + 1);
                $worktree = $baseline;
                $branch = $worktrees->currentBranch($baseline);
                $chainBase = $direct->head($project);

                $task->forceFill([
                    'worktree_path' => $worktree,
                    'branch' => $branch,
                    'chain_base' => $chainBase,
                    'attempt_seq' => $attempt,
                ])->save();
            } else {
                // Satu worktree per task+attempt (wt-{run}-{task}-{attempt}):
                // percobaan revisi TIDAK memakai ulang worktree percobaan
                // sebelumnya — ia dibuat dari cabang percobaan sebelumnya,
                // sehingga file hasil percobaan lama diwarisi lewat git,
                // tapi tidak ada lagi tabrakan nama cabang seperti #6.
                // attempt: PENDING -> 1; REVISION -> revision_count+1
                // (minimal 2, karena status REVISION selalu berarti sudah
                // ada percobaan sebelumnya).
                $attempt = max(
                    (int) $task->attempt_seq + 1,
                    $originalStatus === 'REVISION' ? max(2, $task->revision_count + 1) : 1
                );
                $branch = 'wt-'.$run->id.'-'.$task->id.'-'.$attempt;
                // Percobaan harus unik: cabang yang sudah ada berarti attempt ini
                // pernah dipakai (mis. PO mereset revision_count setelah task
                // diblokir), dan kartu kanban dijodohkan lewat nama attempt —
                // memakai attempt lama akan mengambil kartu lama yang sudah
                // blocked/done sehingga run langsung gagal. Majukan attempt
                // sampai menemukan cabang yang belum ada.
                while ($worktrees->branchExists($baseline, $branch)) {
                    $attempt++;
                    $branch = 'wt-'.$run->id.'-'.$task->id.'-'.$attempt;
                }
                // Model berurutan (keputusan Bima 2026-10-31): worktree task
                // DIRANTAI — attempt pertama dibuat dari cabang task
                // sebelumnya yang sudah QA-APPROVED (bukan selalu dari
                // baseline), agar agen benar-benar MELIHAT file hasil task
                // sebelumnya, bukan hanya membacanya di prompt. Revisi tetap
                // bercabang dari attempt sebelumnya (rantai task itu sendiri),
                // dan chain base-nya tetap basis attempt pertama.
                if ($attempt > 1) {
                    // Ambil attempt terdekat yang cabangnya benar-benar ada:
                    // setelah attempt dimajukan, attempt-1 bisa saja tidak ada
                    // sehingga rantai perubahan hilang tanpa pesan.
                    $previous = $attempt - 1;
                    while ($previous >= 1 && ! $worktrees->branchExists($baseline, 'wt-'.$run->id.'-'.$task->id.'-'.$previous)) {
                        $previous--;
                    }
                    $fromBranch = $previous >= 1 ? 'wt-'.$run->id.'-'.$task->id.'-'.$previous : null;
                    $chainBase = $task->chain_base;
                } else {
                    $predecessor = $this->previousApprovedTask($run, $task);
                    $chainBase = $predecessor?->branch ?: null;
                    $fromBranch = $chainBase;
                }
                $worktree = $worktrees->create($baseline, $branch, $fromBranch);
                $task->forceFill(['worktree_path' => $worktree, 'branch' => $branch, 'chain_base' => $chainBase, 'attempt_seq' => $attempt])->save();
            }

            $useHermes = config('ai.execution_driver') === 'hermes';
            // Konteks hasil task sebelumnya yang sudah QA-APPROVED dalam
            // run yang sama — dibangun SEKALI di sini (shared) sehingga
            // berlaku untuk driver internal maupun Hermes. Ringkas:
            // judul + ringkasan + daftar file, BUKAN diff penuh.
            $previousContext = $this->previousApprovedContext($run, $task);
            $previousSection = $previousContext !== ''
                ? 'Hasil task sebelumnya dalam run ini (sudah disetujui QA — bangun di atasnya, jangan mengubah keputusan/interface yang sudah diputuskan kecuali task ini memintanya):'."\n".$previousContext
                : '';
            // Prompt Hermes tidak menyebut tool lama (read_file/apply_edit/
            // submit_task tidak ada di Hermes) — ia bekerja dengan tool
            // file-nya sendiri di worktree; aturan kerja keras ditambahkan
            // HermesTaskRunner di akhir prompt.
            // File-scope (keputusan 1 Nov): todo dikirim ke Hermes BERSAMA
            // daftar file terkait perubahan ini, agar Hermes tidak mengubah
            // file/project yang salah. Sumber daftar: payload PermissionRequest
            // yang disetujui PO untuk run ini (di sanalah SM menyimpan
            // files per task); bila tidak ada, fallback ke bagian FILE di
            // description task. Daftar ini hanya instruksi di prompt —
            // tidak mengubah gerbang permission, QA, merge, atau demo.
            $fileScopeSection = $useHermes ? $this->fileScopeSection($run, $task, $project?->name) : '';
            $prompt = $useHermes ? implode("\n\n", array_filter([
                "Kamu {$task->assigneeName()}. Kerjakan task berikut pada project Laravel di direktori kerja ini.",
                $originalStatus === 'REVISION' && $task->qa_feedback
                    ? 'REVISI (putaran '.($task->revision_count + 1).'): perbaiki berdasarkan feedback QA berikut: '.$task->qa_feedback
                    : 'Task: '.$task->title.' — '.$task->description,
                $fileScopeSection,
                $previousSection,
                $this->criteriaSection($run, $task),
            ])) : implode("\n\n", array_filter([
                "Kamu {$task->assigneeName()}. Kerjakan task berikut pada project Laravel HANYA lewat tool yang tersedia.",
                'Langkah: baca file yang relevan dengan read_file, ubah dengan apply_edit (old_text harus cocok persis tepat satu kali), buat file baru dengan create_file, dan akhiri dengan submit_task berisi ringkasan perubahan. Jangan menulis code di teks chat.',
                $originalStatus === 'REVISION' && $task->qa_feedback
                    ? 'REVISI (putaran '.($task->revision_count + 1).'): perbaiki berdasarkan feedback QA berikut: '.$task->qa_feedback
                    : 'Task: '.$task->title.' — '.$task->description,
                $previousSection,
                $this->criteriaSection($run, $task),
            ]));

            // Jalur OpenCode (bila peran ini diaktifkan di OPENCODE_ROLES):
            // coding agent eksternal mengedit worktree, lalu Laravel tetap
            // melakukan commit + QA + merge seperti biasa.
            if ($executor === ExecutorPlanner::OPENCODE) {
                $this->executeWithOpenCode($run, $task, $worktree, $prompt, $worktrees, $baseline);

                return;
            }
            // JALUR KANBAN (perubahan 1 Nov, atas perintah Bima): eksekutor
            // bukan lagi Laravel yang memanggil Hermes satu query
            // (HermesTaskRunner) — Laravel hanya membuat board project +
            // kartu todo, lalu MENUNGGU lewat KanbanExecutionPollJob.
            // Yang mengerjakan kartu adalah worker profile Hermes lewat
            // dispatcher gateway. Board diturunkan dari project yang
            // tertaut dengan user story run ini (satu project = satu
            // board), bukan dibuat manual per story. Gerbang tetap di
            // Laravel: izin PO sudah lewat sebelum job ini, dan commit +
            // diff + QA + test gate + merge tetap terjadi di poll job
            // setelah kartu done — bukan oleh worker kanban.
            // Pembuat kartu di sini adalah kode ini yang menerjemahkan
            // todo list breakdown SM; SM profile sendiri belum membuat
            // kartu lewat tool kanban_create (langkah lanjutan, lihat
            // KanbanClient). HermesTaskRunner tidak lagi dipakai job ini.
            if ($useHermes) {
                if (! $project) {
                    throw new RuntimeException('Project run tidak ditemukan untuk board kanban.');
                }
                $kanban = app(KanbanClient::class);
                $board = $kanban->boardSlugForProject($project);
                $kanban->ensureBoard($board, $project->name, 'Board eksekusi project '.$project->name.' dari website Scrum — user story, estimasi multi-agent, dan task todo dari Scrum Master.');
                $profile = collect(config('agents.profiles', []))
                    ->firstWhere('slug', $task->assignee)['profile'] ?? $task->assignee;
                $cardBody = $prompt."\n\nATURAN PENYELESAIAN KANBAN: kerjakan hanya di direktori kerja ini, jangan commit, push, atau merge, jangan mengubah file di luar FILE TERKAIT, dan tandai kartu selesai dengan tool kanban_complete setelah memverifikasi perubahanmu (bukan sebelum). JANGAN menjalankan perintah git apa pun (termasuk git worktree/prune/checkout/commit/clean) — Laravel yang mengurus seluruh operasi git, dan perintah git dari worker pernah merusak registrasi worktree sehingga run gagal. Laravel akan melakukan commit, QA, test gate, dan merge setelah kartu selesai.";
                $cardId = $kanban->createTask(
                    $board,
                    'Task #'.$task->id.': '.$task->title,
                    $cardBody,
                    $profile,
                    $worktree,
                    'scrum-exec-'.$run->id.'-'.$task->id.'-'.$attempt,
                );
                // Titik awal watchdog: aktivitas dihitung sejak kartu dibuat.
                $task->forceFill(['last_progress_at' => now()])->save();
                $run->messages()->create([
                    'execution_task_id' => $task->id,
                    'sender_slug' => 'scrum-master',
                    'recipient_slug' => $task->assignee,
                    'content' => 'Task dikirim ke board kanban '.$board.' sebagai kartu '.$cardId.' untuk worker '.$profile.' (riwayat multi-agent tercatat di kartu kanban).',
                    'metadata' => ['kanban_board' => $board, 'kanban_task_id' => $cardId, 'revision' => $task->revision_count],
                ]);
                KanbanExecutionPollJob::dispatch($run->id, $task->id);

                return;
            }
            // Pekerjaan atas project PO: tanpa filter sensor (aturan PO:
            // tidak ada batasan terhadap project, termasuk env/kuncinya).
            // Jalur tool-call menggantikan patch JSON di chat (sumber
            // gagal parse eksekusi US-01 #1–#8): runner mengeksekusi
            // tiap tool terhadap worktree dan mengembalikan daftar file
            // yang berubah + ringkasan; kegagalan di tengah loop
            // mengembalikan worktree ke kondisi semula.
            $result = $runner->run($task->assignee, $worktree, $prompt, true, [
                'execution_run_id' => $run->id,
                'execution_task_id' => $task->id,
                'source' => 'execution',
            ]);
            $applied = $result['files'];
            $patch = ['summary' => $result['summary']];

            $worktrees->commit($worktree, 'Task #'.$task->id.': '.$task->title);

            $run->messages()->create([
                'execution_task_id' => $task->id,
                'sender_slug' => $task->assignee,
                'recipient_slug' => 'qa-engineer',
                'content' => $patch['summary'].' ('.count($applied).' file: '.implode(', ', array_slice($applied, 0, 5)).(count($applied) > 5 ? ', ...' : '').')',
                'metadata' => ['files' => $applied, 'revision' => $task->revision_count],
            ]);

            // Simpan diff hasil host per task agar halaman eksekusi bisa
            // menampilkannya walau cabang/worktree sudah dibersihkan.
            // Diff terhadap chain base (bukan baseline) agar tetap PER TASK
            // pada model berantai; task pertama / run lama (chain_base
            // kosong) tetap memakai HEAD seperti dulu.
            $diffSnapshot = $worktrees->diff($baseline, $branch, $task->chain_base ?: 'HEAD');

            // QA batch: task berhenti di QA_PENDING, dinilai sekali di akhir.
            if (($run->qa_mode ?: 'per_task') === 'batch') {
                $task->forceFill(['diff_snapshot' => $diffSnapshot])->save();
                app(BatchQa::class)->taskReady($run, $task);

                return;
            }

            $task->forceFill(['status' => 'IN_REVIEW', 'diff_snapshot' => $diffSnapshot])->save();
            $run->forceFill(['status' => 'REVIEWING'])->save();
            ReviewExecutionTaskJob::dispatch($run->id, $task->id);
        } catch (Throwable $exception) {
            // Seperti di ReviewExecutionTaskJob: kegagalan AI sementara
            // dilepas kembali ke antrean (task dikembalikan ke status
            // semula agar guard percobaan berikutnya lolos), bukan
            // failRun. Worktree/cabang task dipertahankan untuk
            // percobaan berikutnya (create() sudah aman untuk cabang
            // yang ada sejak perbaikan #6).
            if ($exception instanceof AiUnavailableException
                && AiGateway::isTransient($exception->errorCode)
                && $this->attempts() < AiGateway::MAX_QUEUE_ATTEMPTS) {
                $task->forceFill(['status' => $originalStatus, 'last_error' => $exception->errorCode])->save();
                AiGateway::logFailure($this->runId, $this->taskId, 'ExecuteExecutionTaskJob', $exception, $this->attempts(), true);
                $this->release(AiGateway::releaseDelay($this->attempts(), $exception->retryAfter));

                return;
            }
            $this->failRun($run, $task, 'EXECUTION_FAILED', $worktrees, $baseline, $exception->getMessage());
            throw $exception;
        }
    }

    /**
     * Blok PROJECT + FILE TERKAIT untuk prompt Hermes.
     *
     * Project diidentifikasi dari nama project run ini (bukan path server),
     * dan worktree yang dipakai Hermes memang salinan project itu — jadi
     * instruksi ini mengikat nama project ke direktori kerja yang sama.
     */
    private function fileScopeSection(ExecutionRun $run, ExecutionTask $task, ?string $projectName): string
    {
        $projectName = trim($projectName ?? '') !== '' ? trim($projectName) : 'project ini';
        $header = 'PROJECT YANG DIKERJAKAN: '.$projectName.'. Direktori kerja ini adalah salinan project itu saja — bukan project lain.';
        $files = $this->relatedFiles($run, $task);
        if ($files === []) {
            return $header."\n".'FILE TERKAIT: tidak ada daftar file terpisah untuk task ini — ubah HANYA file yang disebut di bagian FILE pada description task di atas, jangan file lain di project ini, apalagi project lain.';
        }

        return $header."\n".'FILE TERKAIT PERUBAHAN INI — hanya file-file ini yang boleh diubah/dibuat:'."\n".'- '.implode("\n- ", $files)."\n".'DILARANG mengubah file di luar daftar ini atau file project lain. Kalau file di daftar belum ada, buat file itu di path tersebut, jangan menggantinya dengan file lain. Kalau satu file ternyata tidak relevan, lewati file itu dan lanjutkan file lainnya, jangan mencari pengganti sendiri.';
    }

    /**
     * Kriteria story bersifat kumulatif lintas task. Tanpa batas eksplisit,
     * worker memblokir kartu karena kriteria menyebut file di luar FILE
     * TERKAIT (run #6: task controller diblokir sebab AC story menyebut
     * kolom di view). Bagian ini menjelaskan bagian mana yang milik task ini.
     */
    private function criteriaSection(ExecutionRun $run, ExecutionTask $task): string
    {
        $files = $this->relatedFiles($run, $task);
        $scope = $files === []
            ? 'file yang disebut di bagian FILE pada description task'
            : implode(', ', $files);

        return 'Tugas dari: '.$run->subjectTitle()."\n"
            .'Kriteria story (kumulatif — dipenuhi bersama task lain dalam run ini): '.$run->subjectCriteria()."\n"
            .'BATAS TASK INI: kamu hanya bertanggung jawab atas bagian kriteria yang menyangkut '.$scope.'. '
            .'Kriteria yang menyebut file, halaman, atau komponen lain adalah tanggung jawab task lain — '
            .'JANGAN memblokir kartu karena bagian itu, selesaikan bagianmu lalu tandai kartu selesai.';
    }

    /**
     * Daftar file terkait satu task, dari sumber yang sudah ada.
     *
     * 1) Payload PermissionRequest run ini (SM menyimpan files per task di
     *    sana, dan PO menyetujui daftar itu): cocokkan dulu lewat
     *    sort_order sebagai indeks, lalu lewat title + assignee.
     * 2) Fallback: parse bagian FILE di description task (format todo-list
     *    baru: KERJAKAN / FILE / HASIL / SELESAI BILA).
     * Path dibersihkan: hanya path relatif, tanpa .. dan tanpa path absolut
     * — path di luar worktree tidak pernah diteruskan ke Hermes.
     */
    private function relatedFiles(ExecutionRun $run, ExecutionTask $task): array
    {
        $requests = PermissionRequest::query()
            ->where('execution_run_id', $run->id)
            ->orderBy('id', 'desc')
            ->get();
        foreach ($requests as $request) {
            $payloadTasks = $request->payload['tasks'] ?? null;
            if (! is_array($payloadTasks) || $payloadTasks === []) {
                continue;
            }
            $candidate = $payloadTasks[$task->sort_order] ?? null;
            if (! is_array($candidate) || ($candidate['title'] ?? null) !== $task->title) {
                $candidate = null;
                foreach ($payloadTasks as $payloadTask) {
                    if (is_array($payloadTask)
                        && ($payloadTask['title'] ?? null) === $task->title
                        && ($payloadTask['assignee'] ?? null) === $task->assignee) {
                        $candidate = $payloadTask;
                        break;
                    }
                }
            }
            if (is_array($candidate) && is_array($candidate['files'] ?? null)) {
                $cleaned = $this->cleanPaths($candidate['files']);
                if ($cleaned !== []) {
                    return $cleaned;
                }
            }
        }

        if (preg_match('/FILE\s*:\s*(.+?)(?=\n\s*(HASIL|SELESAI BILA|KERJAKAN)\b|$)/is', $task->description ?? '', $matches)) {
            $parts = preg_split('/[\n,]+/', $matches[1]) ?: [];
            $cleaned = $this->cleanPaths(array_map(
                fn ($part) => trim($part, " \t\r\n-•`\"'"),
                $parts
            ));
            if ($cleaned !== []) {
                return $cleaned;
            }
        }

        return [];
    }

    /** @param  array<int, mixed>  $paths */
    private function cleanPaths(array $paths): array
    {
        $cleaned = [];
        foreach ($paths as $path) {
            if (! is_string($path)) {
                continue;
            }
            $path = trim(trim($path), "`\"'");
            if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/') || str_starts_with($path, '\\')) {
                continue;
            }
            $cleaned[] = $path;
        }

        return array_values(array_unique($cleaned));
    }

    /**
     * Task APPROVED terdekat SEBELUM task ini dalam urutan eksekusi
     * (cabang inilah yang menjadi chain base worktree task ini).
     * Hanya task dengan urutan lebih rendah yang dihitung, agar task
     * yang tertukar urutannya tidak saling menjadi basis.
     */
    private function previousApprovedTask(ExecutionRun $run, ExecutionTask $task): ?ExecutionTask
    {
        return $run->tasks()
            ->where('status', 'APPROVED')
            ->where(function ($query) use ($task): void {
                $query->where('sort_order', '<', $task->sort_order)
                    ->orWhere(function ($query) use ($task): void {
                        $query->where('sort_order', $task->sort_order)
                            ->where('id', '<', $task->id);
                    });
            })
            ->orderBy('sort_order', 'desc')
            ->orderBy('id', 'desc')
            ->first();
    }

    /**
     * Ringkasan hasil semua task APPROVED sebelum task ini, dari pesan
     * handoff agen -> QA (ringkasan + daftar file). Dibatasi agar prompt
     * tidak membengkak: maks 800 karakter per task, 4000 total.
     * String kosong bila belum ada task sebelumnya yang disetujui.
     */
    private function previousApprovedContext(ExecutionRun $run, ExecutionTask $task): string
    {
        $approved = $run->tasks()
            ->where('status', 'APPROVED')
            ->where(function ($query) use ($task): void {
                $query->where('sort_order', '<', $task->sort_order)
                    ->orWhere(function ($query) use ($task): void {
                        $query->where('sort_order', $task->sort_order)
                            ->where('id', '<', $task->id);
                    });
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        if ($approved->isEmpty()) {
            return '';
        }
        $lines = [];
        $total = 0;
        foreach ($approved as $previous) {
            $message = $run->messages()
                ->where('execution_task_id', $previous->id)
                ->where('sender_slug', $previous->assignee)
                ->latest('id')
                ->first();
            $summary = $message ? trim($message->content) : '';
            // Pesan handoff menempelkan daftar file di akhir
            // "(N file: ...)"; kupas agar tidak duplikat dengan daftar
            // file lengkap dari metadata di bawah.
            $summary = preg_replace('/ \(\d+ file: .*\)$/', '', $summary) ?? $summary;
            $files = $message?->metadata['files'] ?? [];
            $entry = '- '.$previous->title.' ('.$previous->assigneeName().')';
            if ($summary !== '') {
                $entry .= ': '.mb_substr($summary, 0, 800);
            }
            if (is_array($files) && $files !== []) {
                $entry .= ' [file: '.implode(', ', array_slice($files, 0, 20)).']';
            }
            $lines[] = $entry;
            $total += strlen($entry);
            if ($total > 4000) {
                break;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Ekstraksi JSON toleran — sama seperti vote estimasi (68878fb) dan
     * breakdown (435016d). Sebelum perbaikan ini, validatedPatch hanya
     * mengupas fence bila fence persis di awal/akhir jawaban, sehingga
     * fence + prosa dari agent langsung menggagalkan eksekusi dengan
     * JsonException "Syntax error" (kasus eksekusi US-01 #2, task #2).
     */
    private function extractJsonObject(string $raw): ?string
    {
        $text = trim($raw);
        if (preg_match('/```(?:json)?\s*(.*?)```/s', $text, $matches)) {
            $text = trim($matches[1]);
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        return substr($text, $start, $end - $start + 1);
    }

    /** @return array{files:array,summary:string} */
    private function validatedPatch(string $raw): array
    {
        if (strlen($raw) > 2000000) {
            throw new RuntimeException('Patch terlalu besar.');
        }
        $candidate = $this->extractJsonObject($raw);
        if ($candidate === null) {
            // Kegagalan eksekusi US-01 #3 (13:42:12 UTC) justru jalur ini:
            // RuntimeException tanpa payload tercatat, sehingga bentuk
            // jawaban agent tidak bisa didiagnosis. Catat selalu.
            Log::warning('Execution patch failed validation', [
                'execution_run_id' => $this->runId,
                'execution_task_id' => $this->taskId,
                'raw_excerpt' => mb_substr($raw, 0, 2000),
                'error' => 'Format patch agent tidak valid (tanpa kurung kurawal).',
            ]);
            throw new RuntimeException('Format patch agent tidak valid.');
        }
        try {
            $data = json_decode($candidate, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            // Catat potongan jawaban gagal — kegagalan eksekusi US-01 #2
            // dulu tidak bisa didiagnosis karena payload mentah tidak
            // tercatat di mana pun.
            Log::warning('Execution patch failed validation', [
                'execution_run_id' => $this->runId,
                'execution_task_id' => $this->taskId,
                'raw_excerpt' => mb_substr($raw, 0, 2000),
                'error' => $exception->getMessage(),
            ]);
            throw $exception;
        }
        if (! is_array($data) || ! is_array($data['files'] ?? null) || ! is_string($data['summary'] ?? null)) {
            Log::warning('Execution patch failed validation', [
                'execution_run_id' => $this->runId,
                'execution_task_id' => $this->taskId,
                'raw_excerpt' => mb_substr($raw, 0, 2000),
                'error' => 'Format patch agent tidak valid (bentuk JSON salah).',
            ]);
            throw new RuntimeException('Format patch agent tidak valid.');
        }

        return ['files' => array_values($data['files']), 'summary' => trim($data['summary'])];
    }

    /**
     * Retry otomatis 1x dengan patch yang lebih kecil (maks 2 file) bila patch
     * asli ditolak validasi penerapan ATAU gagal validasi bentuk (kasus
     * eksekusi US-01 #3). Guard patch_retry_count mencegah infinite loop:
     * retry tidak pernah me-retry lagi — gagal -> notifikasi + throw.
     *
     * @return array{applied:string[],patch:array{files:array,summary:string}}
     */
    private function retryWithSmallerPatch(
        AgentProfileClient $agents,
        ExecutionRun $run,
        ExecutionTask $task,
        PatchApplier $patcher,
        string $worktree,
        Throwable $validationError,
    ): array {
        if ($task->patch_retry_count >= 1) {
            // Seharusnya tidak terjadi (hanya dipanggil sekali), tapi jaga-jaga.
            // Notifikasi ditangani oleh failRun() di outer catch — jangan duplikasi.
            throw $validationError;
        }

        $task->forceFill(['patch_retry_count' => $task->patch_retry_count + 1])->save();

        // Catatan: $task->status sudah IN_PROGRESS di titik ini, jadi deteksi
        // revisi QA dari qa_feedback yang terisi (bukan dari status).
        $isQaRevision = filled($task->qa_feedback);

        $retryPrompt = implode("\n\n", array_filter([
            "Patch kamu DITOLAK sistem karena: {$validationError->getMessage()}",
            'Pecah menjadi patch yang LEBIH KECIL: maksimal 2 file saja, kirim ulang hanya file yang paling penting dulu.',
            'Kembalikan JSON tepat: {"files":[{"path":"relatif/ke/file.php","action":"create|update|delete","content":"isi lengkap file"}],"summary":"ringkasan perubahan"}.',
            'Aturan: path relatif dari root project, tanpa .. atau path absolut; action delete tanpa content; tulis code lengkap yang valid, bukan potongan; JANGAN sertakan penjelasan di luar JSON.',
            // Jangan hilangkan konteks QA saat retry di putaran revisi.
            $isQaRevision
                ? 'REVISI (putaran '.($task->revision_count + 1).'): perbaiki berdasarkan feedback QA berikut, lalu kirim ulang SEMUA file yang berubah: '.$task->qa_feedback
                : null,
            'Task: '.$task->title.' — '.$task->description,
        ]));

        try {
            $rawRetry = $agents->sendMessage(
                $task->assignee,
                $retryPrompt,
                'execution-'.$run->id.'-task-'.$task->id.'-retry1',
                false,
                true
            );
            $patchRetry = $this->validatedPatch($rawRetry);
            $applied = $patcher->apply($worktree, $patchRetry['files'], true);

            return ['applied' => $applied, 'patch' => $patchRetry];
        } catch (Throwable $retryError) {
            throw $retryError;
        }
    }

    /**
     * Jalankan task lewat OpenCode CLI, lalu serahkan ke jalur QA yang sama.
     *
     * Tidak ada kartu kanban di jalur ini: OpenCode mengedit worktree secara
     * langsung, Laravel memverifikasi perubahan file sebelum commit.
     */
    private function executeWithOpenCode(
        ExecutionRun $run,
        ExecutionTask $task,
        string $worktree,
        string $prompt,
        WorktreeManager $worktrees,
        string $baseline
    ): void {
        $task->forceFill(['last_progress_at' => now()])->save();

        $run->messages()->create([
            'execution_task_id' => $task->id,
            'sender_slug' => 'scrum-master',
            'recipient_slug' => $task->assignee,
            'content' => 'Task #'.$task->id.' dikirim ke OpenCode (agen coding eksternal).',
        ]);

        $result = app(OpenCodeRunner::class)->run(
            $worktree,
            $prompt."\n\n".$this->openCodeRules()
        );

        $task->forceFill(['last_progress_at' => now()])->save();

        // Provider kadang memblokir jawaban model lewat content filter
        // (terjadi pada run #4). Coba sekali dengan model cadangan sebelum
        // menyerahkan task ke Hermes.
        if (! $result['ok'] && str_contains(strtolower($result['output']), 'content filter')) {
            $fallbackModel = (string) config('ai.opencode.fallback_model', '');
            if ($fallbackModel !== '') {
                $retry = app(OpenCodeRunner::class)->run($worktree, $prompt, 0, $fallbackModel);
                if ($retry['ok']) {
                    $result = $retry;
                }
            }
        }

        if (! $result['ok']) {
            if ($this->fallbackToHermes($run, $task, 'OpenCode gagal (exit '.$result['exit'].'): '.$result['output'])) {
                return;
            }

            $this->failRun($run, $task, 'OPENCODE_FAILED', $worktrees, $baseline, 'OpenCode gagal (exit '.$result['exit'].'): '.$result['output']);

            return;
        }

        // OpenCode mengedit working tree (belum commit) — periksa status
        // worktree, bukan diff commit branch.
        $files = $worktrees->dirtyFiles($worktree);

        // Percobaan kedua dengan instruksi lebih tegas: pada run #7 OpenCode
        // menghabiskan langkah untuk eksplorasi (php artisan tinker) lalu
        // selesai tanpa menulis file, sehingga task view jatuh ke Hermes.
        if ($files === []) {
            $strict = 'TULIS FILE SEKARANG. Jangan menjalankan php artisan, tinker, composer, curl, atau tes apa pun. '
                ."Jangan membaca lebih dari 3 file. Gunakan tool write/edit pada file target yang disebut di FILE TERKAIT.\n\n".$prompt;

            $retry = app(OpenCodeRunner::class)->run($worktree, $strict."\n\n".$this->openCodeRules());
            if ($retry['ok']) {
                $files = $worktrees->dirtyFiles($worktree);
            }
        }

        if ($files === []) {
            if ($this->fallbackToHermes($run, $task, 'OpenCode selesai tanpa mengubah file (2 percobaan).')) {
                return;
            }

            $this->failRun($run, $task, 'OPENCODE_NO_CHANGES', $worktrees, $baseline, 'OpenCode selesai tanpa mengubah file apa pun.');

            return;
        }

        $worktrees->commit($worktree, 'Task #'.$task->id.': '.$task->title);

        $run->messages()->create([
            'execution_task_id' => $task->id,
            'sender_slug' => $task->assignee,
            'recipient_slug' => 'qa-engineer',
            'content' => 'OpenCode selesai: '.count($files).' file ('.implode(', ', array_slice($files, 0, 5)).(count($files) > 5 ? ', ...' : '').').',
            'metadata' => ['files' => $files, 'driver' => 'opencode', 'revision' => $task->revision_count],
        ]);

        $task->forceFill([
            'status' => 'IN_REVIEW',
            'diff_snapshot' => $worktrees->diff($baseline, $task->branch, $task->chain_base ?: 'HEAD'),
        ])->save();
        $run->forceFill(['status' => 'REVIEWING'])->save();
        ReviewExecutionTaskJob::dispatch($run->id, $task->id);
    }

    /**
     * Fallback otomatis: task yang gagal di OpenCode dikerjakan ulang Hermes.
     * Hanya untuk kebijakan auto/ab — pada kebijakan opencode, PO memang
     * meminta OpenCode, jadi kegagalan harus terlihat.
     */
    /** Aturan tegas untuk OpenCode agar menulis file, bukan menjelajah. */
    private function openCodeRules(): string
    {
        return implode("\n", [
            'ATURAN WAJIB:',
            '1. Kerjakan hanya di direktori kerja ini.',
            '2. JANGAN menjalankan perintah git.',
            '3. JANGAN menjalankan perintah tes yang memakan waktu (php artisan test, phpunit) kecuali diminta.',
            '4. Boleh membaca file project mana pun yang diperlukan sebagai konteks, termasuk .env project.',
            '5. Perubahan file tetap dibatasi pada FILE TERKAIT; .env hanya dibaca, jangan diubah tanpa diminta.',
            '6. Selesaikan tanpa menunggu konfirmasi; jawaban teks tanpa perubahan file dianggap gagal.',
        ]);
    }

    /**
     * Hanya untuk kebijakan auto/ab — pada kebijakan opencode, PO memang
     * meminta OpenCode, jadi kegagalan harus terlihat.
     */
    private function fallbackToHermes(ExecutionRun $run, ExecutionTask $task, string $reason): bool
    {
        // Saklar operasional: OPENCODE_FALLBACK=never membuat kegagalan
        // OpenCode menghentikan run (fail fast) alih-alih berpindah ke
        // Hermes. Default 'hermes' menjaga run tetap selesai.
        if (strtolower((string) config('ai.opencode.fallback', 'hermes')) === 'never') {
            return false;
        }

        $policy = $run->executor_policy ?: 'auto';
        if (! in_array($policy, ['auto', 'ab'], true) || $task->executor_override === 'hermes') {
            return false;
        }

        app(ExecutorPlanner::class)->fallbackTo($task, 'hermes');
        $task->forceFill([
            'status' => 'REVISION',
            'last_error' => 'OPENCODE_FALLBACK',
            'last_progress_at' => now(),
        ])->save();

        $run->messages()->create([
            'execution_task_id' => $task->id,
            'sender_slug' => 'scrum-master',
            'recipient_slug' => $task->assignee,
            'content' => 'Fallback otomatis: '.$reason.' Task dilanjutkan lewat Hermes.',
        ]);

        ExecuteExecutionTaskJob::dispatch($run->id, $task->id);

        return true;
    }

    private function failRun(ExecutionRun $run, ExecutionTask $task, string $code, ?WorktreeManager $worktrees = null, ?string $baseline = null, ?string $errorMessage = null): void
    {
        $task->forceFill(['status' => 'FAILED', 'last_error' => $errorMessage ?: $code])->save();
        $run->forceFill(['status' => 'FAILED', 'error_code' => $code, 'completed_at' => now()])->save();

        // Notifikasi ke PO untuk SEMUA kegagalan eksekusi (AI error, JSON invalid,
        // patch ditolak, write error) — PO tidak boleh dibiarkan tanpa kabar.
        try {
            $reason = $errorMessage ?: match ($code) {
                'WORKSPACE_MISSING' => 'Workspace project tidak ditemukan.',
                'PERMISSION_REJECTED' => 'PO menolak permintaan izin eksekusi.',
                default => 'Terjadi kesalahan saat eksekusi.',
            };
            Notification::patchFailed($run, $task, $reason);
        } catch (Throwable) {
            // Best-effort: notifikasi tidak boleh menggagalkan failRun.
        }

        // Bersihkan worktree yatim dari SEMUA percobaan task ini:
        // worktree dibuat sebelum pemanggilan AI; bila gagal setelahnya,
        // direktori worktrees/ dan cabang wt-*-* menumpuk bila tidak
        // dihapus di sini.
        if ($worktrees !== null && $baseline !== null) {
            try {
                $worktrees->cleanupTaskWorktrees($baseline, $run->id, $task->id);
            } catch (Throwable) {
                // Best-effort: jangan menutupi error asli yang memicu failRun.
            }
        }
    }
}
