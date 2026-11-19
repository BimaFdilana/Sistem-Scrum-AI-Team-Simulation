<?php

namespace App\Jobs;

use App\Exceptions\AiUnavailableException;
use App\Exceptions\ModelProblemException;
use App\Models\ExecutionRun;
use App\Models\ExecutionTask;
use App\Models\Notification;
use App\Models\Project;
use App\Services\AiGateway;
use App\Services\AntigravityClient;
use App\Services\StrictModelResolver;
use App\Support\Ai\AiQuota;
use App\Support\Ai\ProjectMemory;
use App\Support\Execution\DirectWorkspace;
use App\Support\Execution\ExecutionStopper;
use App\Support\Execution\ExecutorPlanner;
use App\Support\Execution\ProjectFacts;
use App\Support\Execution\TaskCompletion;
use App\Support\Execution\TaskFileScope;
use App\Support\Ui\FriendlyText;
use App\Support\Workspace\WorktreeManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessSignaledException;
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
        // Task agen developer Antigravity bisa berjalan lama (task_timeout
        // 1500 detik) — beri ruang, tetap dengan batas
        // agar kegagalan tetap tercatat bersih.
        $this->timeout = 1800;
    }

    public function handle(
        WorktreeManager $worktrees,
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
                // Sisa perubahan run lain (mis. task yang timeout) dipindahkan ke
                // cabangnya sendiri, bukan lagi menggagalkan semua eksekusi berikutnya.
                $recovered = $direct->recoverLeftovers($project, $run);
                if ($recovered !== null) {
                    $run->messages()->create([
                        'execution_task_id' => $task->id,
                        'sender_slug' => 'scrum-master',
                        'recipient_slug' => 'product-owner',
                        'content' => 'Baseline dirapikan otomatis: '.$recovered,
                    ]);
                }
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

            // Konteks hasil task sebelumnya yang sudah QA-APPROVED dalam
            // run yang sama — dibangun SEKALI di sini (shared) sehingga
            // berlaku untuk driver internal maupun Hermes. Ringkas:
            // judul + ringkasan + daftar file, BUKAN diff penuh.
            $previousContext = $this->previousApprovedContext($run, $task);
            $previousSection = $previousContext !== ''
                ? 'Hasil task sebelumnya dalam run ini (sudah disetujui QA — bangun di atasnya, jangan mengubah keputusan/interface yang sudah diputuskan kecuali task ini memintanya):'."\n".$previousContext
                : '';
            // Daftar FILE TERKAIT dari rencana SM yang disetujui PO dikirim
            // bersama task agar agen tidak mengubah file/project yang salah.
            $fileScopeSection = $this->fileScopeSection($run, $task, $project?->name);
            $factsSection = $this->projectFactsSection($project);
            $prompt = implode("\n\n", array_filter([
                "Kamu {$task->assigneeName()}. Kerjakan task berikut pada project Laravel di direktori kerja ini.",
                $originalStatus === 'REVISION' && $task->qa_feedback
                    ? 'REVISI (putaran '.($task->revision_count + 1).'): perbaiki berdasarkan feedback QA berikut: '.$task->qa_feedback
                    : 'Task: '.$task->title.' — '.$task->description,
                $fileScopeSection,
                $previousSection,
                $this->criteriaSection($run, $task),
                $factsSection,
            ]));

            // Agen developer Antigravity mengedit worktree; Laravel tetap yang
            // memeriksa perubahan, commit, QA, dan merge.
            $this->executeWithAntigravity($run, $task, $worktree, $prompt, $worktrees, $baseline);
        } catch (Throwable $exception) {
            // PO menghentikan eksekusi: jangan menimpa status, jeda, atau mencoba ulang.
            if (ExecutionStopper::wasStopped($run)) {
                $this->afterStop($run, $task);

                return;
            }
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
            // Proses agen dimatikan oleh restart layanan (deploy/systemctl): bukan kegagalan
            // agen. Kembalikan task ke antrean; jangan menggagalkan run yang sedang dikerjakan.
            if (self::wasKilledByRestart($exception) && $this->attempts() < 3) {
                $task->forceFill(['status' => $originalStatus, 'last_error' => 'PROCESS_SIGNALED', 'last_progress_at' => now()])->save();
                $run->messages()->create([
                    'execution_task_id' => $task->id,
                    'sender_slug' => 'scrum-master',
                    'recipient_slug' => $task->assignee ?: 'product-owner',
                    'content' => 'Proses agen dihentikan oleh restart layanan; task diulang otomatis (percobaan '.($this->attempts() + 1).').',
                ]);
                $this->release(30);

                return;
            }
            if (($exception instanceof AiUnavailableException && $exception->errorCode === 'QUOTA_EXHAUSTED')
                || AiQuota::isQuotaText($exception->getMessage())) {
                $this->pauseForQuota($run, $task, $exception->getMessage());

                return;
            }
            $this->failRun($run, $task, 'EXECUTION_FAILED', $worktrees, $baseline, $exception->getMessage());
            throw $exception;
        }
    }

    /** SIGTERM/SIGKILL pada proses anak = layanan di-restart, bukan agen yang gagal. */
    public static function wasKilledByRestart(Throwable $exception): bool
    {
        return $exception instanceof ProcessSignaledException
            && in_array($exception->getProcess()->getTermSignal(), [9, 15], true);
    }

    /** Skema database dan rute nyata project; kosong bila tidak bisa dibaca (tidak menggagalkan task). */
    private function projectFactsSection(?Project $project): string
    {
        if ($project === null) {
            return '';
        }

        try {
            return app(ProjectFacts::class)->section($project);
        } catch (Throwable $exception) {
            Log::warning('Fakta project tidak bisa dibaca', ['project' => $project->id, 'error' => $exception->getMessage()]);

            return '';
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
        return (new TaskFileScope)->filesFor($run, $task);
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
     * Jalur Antigravity: agen developer (profil peran agy) mengedit worktree
     * langsung, lalu Laravel memeriksa file yang berubah, commit, dan
     * mengirim ke QA. Error sementara dilempar sebagai AiUnavailableException
     * agar job dilepas kembali ke antrean oleh penanganan di handle().
     */
    private function executeWithAntigravity(
        ExecutionRun $run,
        ExecutionTask $task,
        string $worktree,
        string $prompt,
        WorktreeManager $worktrees,
        string $baseline
    ): void {
        $model = app(StrictModelResolver::class)->require($task->assignee ?: 'backend-developer');
        $agent = (string) (config('ai.antigravity.role_agents.'.$task->assignee) ?: 'scrumspace-backend');
        $usageContext = ['execution_run_id' => $run->id, 'execution_task_id' => $task->id, 'source' => 'execution'];

        $task->forceFill(['last_progress_at' => now()])->save();
        $run->messages()->create([
            'execution_task_id' => $task->id,
            'sender_slug' => 'scrum-master',
            'recipient_slug' => $task->assignee,
            'content' => 'Task #'.$task->id.' dikirim ke developer AI.',
        ]);

        // Memori proyek (keputusan PO, pelajaran QA, riwayat kode) dipasang
        // sebagai rules agy di worktree, dikecualikan dari git.
        if ($project = $run->project()) {
            rescue(fn () => app(ProjectMemory::class)->installInto($worktree, $project), null, true);
        }

        $client = app(AntigravityClient::class);
        $result = $client->runTask($agent, $worktree, $prompt."\n\n".$this->agentRules(), $model, 0, $usageContext);
        $task->forceFill(['last_progress_at' => now()])->save();

        if (ExecutionStopper::wasStopped($run) || $result['error'] === 'STOPPED_BY_PO') {
            $this->afterStop($run, $task);

            return;
        }

        $files = $result['ok'] ? $worktrees->dirtyFiles($worktree) : [];

        // Sekali lagi dengan instruksi lebih tegas bila agen selesai tanpa
        // menulis file (pelajaran run #7 OpenCode: habis untuk eksplorasi).
        if ($result['ok'] && $files === []) {
            $strict = 'TULIS FILE SEKARANG. Jangan menjalankan php artisan, tinker, composer, atau tes apa pun. '
                ."Jangan membaca lebih dari 3 file. Ubah file target yang disebut di FILE TERKAIT.\n\n".$prompt;
            $result = $client->runTask($agent, $worktree, $strict."\n\n".$this->agentRules(), $model, 0, $usageContext);
            $task->forceFill(['last_progress_at' => now()])->save();
            $files = $result['ok'] ? $worktrees->dirtyFiles($worktree) : [];
        }

        if (! $result['ok']) {
            $this->handleAntigravityFailure($run, $task, $result, $model, $worktrees, $baseline);

            return;
        }

        if ($files === []) {
            $this->failRun($run, $task, 'AGENT_NO_CHANGES', $worktrees, $baseline, 'Developer AI selesai tanpa mengubah file apa pun (2 percobaan).');

            return;
        }

        AiQuota::recovered();
        $worktrees->commit($worktree, 'Task #'.$task->id.': '.$task->title);

        $run->messages()->create([
            'execution_task_id' => $task->id,
            'sender_slug' => $task->assignee,
            'recipient_slug' => 'qa-engineer',
            'content' => 'Developer AI selesai: '.count($files).' file ('.implode(', ', array_slice($files, 0, 5)).(count($files) > 5 ? ', ...' : '').').',
            'metadata' => ['files' => $files, 'driver' => 'antigravity', 'revision' => $task->revision_count],
        ]);

        app(TaskCompletion::class)->submitForQa($run, $task, $worktrees->diff($baseline, $task->branch, $task->chain_base ?: 'HEAD'));
    }

    /**
     * @param  array{ok: bool, exit: ?int, output: string, error: ?string, usage: array}  $result
     */
    private function handleAntigravityFailure(ExecutionRun $run, ExecutionTask $task, array $result, string $model, WorktreeManager $worktrees, string $baseline): void
    {
        $error = (string) ($result['error'] ?? 'AGY_FAILED');

        if ($error === 'QUOTA_EXHAUSTED') {
            $this->pauseForQuota($run, $task, (string) $result['output']);

            return;
        }
        if ($error === 'MODEL_PROBLEMATIC') {
            throw new ModelProblemException($model, app(StrictModelResolver::class)->modelProblemMessage($model));
        }
        if (AiGateway::isTransient($error)) {
            throw new AiUnavailableException($error, 'Developer AI: '.FriendlyText::clean($result['output']), null, $model);
        }
        if ($error === 'NOT_LOGGED_IN') {
            $this->failRun($run, $task, 'AI_NOT_LOGGED_IN', $worktrees, $baseline, 'Akun Antigravity belum login atau sesi login habis. Hubungkan ulang akun Google di halaman AI Model, lalu jalankan ulang story.');

            return;
        }

        $this->failRun($run, $task, 'AGENT_FAILED', $worktrees, $baseline, 'Developer AI gagal (kode '.$result['exit'].'): '.FriendlyText::clean($result['output']));
    }

    /** Aturan tegas untuk agen developer agar menulis file, bukan menjelajah. */
    private function agentRules(): string
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
     * Kuota AI habis: eksekusi DIJEDA (bukan digagalkan) dan menunggu PO. Setelah
     * PO mengganti model aktif atau kuota pulih, tombol "Lanjutkan" menjalankan
     * ulang task ini dari awal. PO juga diberi tahu (banner, notifikasi, chat).
     */
    private function pauseForQuota(ExecutionRun $run, ExecutionTask $task, string $detail): void
    {
        $model = rescue(fn () => app(StrictModelResolver::class)->resolve('backend-developer'), null, false);
        AiQuota::exhausted(is_string($model) ? $model : null, $detail);

        $task->forceFill(['status' => 'BLOCKED', 'last_error' => 'QUOTA_EXHAUSTED', 'last_progress_at' => now()])->save();
        $run->messages()->create([
            'execution_task_id' => $task->id,
            'sender_slug' => 'scrum-master',
            'recipient_slug' => 'product-owner',
            'content' => 'Task #'.$task->id.' dijeda: kuota model AI habis. Ganti model aktif di halaman AI Model atau tunggu kuota pulih, lalu klik Lanjutkan.',
        ]);
        $run->transitionTo(ExecutionRun::NEEDS_PO, ['EXECUTING', 'REVIEWING', 'QA_REVIEW'], [
            'error_code' => 'QUOTA_EXHAUSTED',
            'summary' => 'Kuota model AI'.(is_string($model) ? ' '.$model : '').' habis, jadi tim berhenti di task #'.$task->id.' ('.$task->title.'). '
                .'Ganti model aktif di halaman AI Model atau tunggu kuota pulih (biasanya beberapa jam), lalu klik Lanjutkan.',
        ]);
    }

    /** Run dihentikan PO saat task ini berjalan: batalkan task dan kembalikan baseline. */
    private function afterStop(ExecutionRun $run, ExecutionTask $task): void
    {
        $task->forceFill(['status' => 'CANCELLED', 'last_error' => ExecutionStopper::CODE, 'last_progress_at' => now()])->save();
        $note = app(ExecutionStopper::class)->release($run->fresh());
        $run->messages()->create([
            'execution_task_id' => $task->id,
            'sender_slug' => 'scrum-master',
            'recipient_slug' => 'product-owner',
            'content' => 'Eksekusi dihentikan paksa oleh PO; task #'.$task->id.' dibatalkan.'.($note ? ' '.$note : ''),
        ]);
    }

    private function failRun(ExecutionRun $run, ExecutionTask $task, string $code, ?WorktreeManager $worktrees = null, ?string $baseline = null, ?string $errorMessage = null): void
    {
        $task->forceFill(['status' => 'FAILED', 'last_error' => $errorMessage ?: $code])->save();
        $run->forceFill([
            'status' => 'FAILED',
            'error_code' => $code,
            // Alasan yang bisa dibaca PO; dulu kosong sehingga layar hanya
            // menampilkan kode EXECUTION_FAILED tanpa penjelasan.
            'summary' => 'Task #'.$task->id.' ('.$task->title.') gagal: '.Str::limit(FriendlyText::clean((string) ($errorMessage ?: $code)), 600),
            'completed_at' => now(),
        ])->save();

        // Mode langsung: simpan pekerjaan setengah jadi di cabang run ini dan
        // kembalikan baseline ke cabang basis agar eksekusi berikutnya bisa jalan.
        if (($run->mode ?: config('execution.mode', 'worktree')) === 'direct' && ($project = $run->project()) !== null) {
            try {
                $note = app(DirectWorkspace::class)->releaseAfterFailure($project, $run);
                if ($note !== null) {
                    $run->messages()->create([
                        'execution_task_id' => $task->id,
                        'sender_slug' => 'scrum-master',
                        'recipient_slug' => 'product-owner',
                        'content' => 'Eksekusi dihentikan. '.$note,
                    ]);
                }
            } catch (Throwable) {
                // Best-effort: eksekusi berikutnya tetap memulihkan lewat recoverLeftovers().
            }
        }

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
