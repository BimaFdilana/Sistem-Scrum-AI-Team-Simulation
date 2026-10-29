<?php

namespace App\Jobs;

use App\Models\ExecutionRun;
use App\Models\ExecutionTask;
use App\Models\Notification;
use App\Services\AgentProfileClient;
use App\Support\Workspace\PatchApplier;
use App\Support\Workspace\PatchValidationException;
use App\Support\Workspace\WorktreeManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
    ) {}

    public function handle(
        AgentProfileClient $agents,
        WorktreeManager $worktrees,
        PatchApplier $patcher,
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
        $task->forceFill(['status' => 'IN_PROGRESS'])->save();

        try {
            $worktrees->ensureRepo($baseline);
            $branch = 'exec-'.$run->id.'-task-'.$task->id;
            // Pengerjaan revisi (status REVISION) melanjutkan worktree +
            // cabang task yang sudah ada — bukan membuat cabang baru
            // dengan nama yang sama (regresi eksekusi US-01 #6: create()
            // lama selalu `add -b` sehingga revisi pertama langsung fatal
            // "branch already exists" sebelum agent dipanggil).
            if ($task->status === 'REVISION' && $task->branch === $branch
                && $task->worktree_path && is_dir($task->worktree_path)) {
                $worktree = $task->worktree_path;
            } else {
                $worktree = $worktrees->create($baseline, $branch);
                $task->forceFill(['worktree_path' => $worktree, 'branch' => $branch])->save();
            }

            $assigneeName = $task->assigneeName();
            $prompt = implode("\n\n", [
                "Kamu {$assigneeName}. Kerjakan task berikut pada project Laravel.",
                'Kembalikan JSON tepat: {"files":[{"path":"relatif/ke/file.php","action":"create|update|delete","content":"isi lengkap file"}],"summary":"ringkasan perubahan"}.',
                'Aturan: path relatif dari root project, tanpa .. atau path absolut; action delete tanpa content; maksimal '.PatchApplier::MAX_FILES.' file; tulis code lengkap yang valid, bukan potongan; JANGAN sertakan penjelasan di luar JSON.'
                    .($run->repair_order_id ? ' BATASAN PERINTAH PERBAIKAN: dilarang menghapus file (action delete) dan dilarang operasi destruktif terhadap data; lakukan hanya perubahan minimal yang aman.' : ''),
                $task->status === 'REVISION' && $task->qa_feedback
                    ? 'REVISI (putaran '.($task->revision_count + 1).'): perbaiki berdasarkan feedback QA berikut, lalu kirim ulang SEMUA file yang berubah: '.$task->qa_feedback
                    : 'Task: '.$task->title.' — '.$task->description,
                'Tugas dari: '.$run->subjectTitle().' | Kriteria: '.$run->subjectCriteria(),
            ]);
            // Pekerjaan atas project PO: tanpa filter sensor (aturan PO:
            // tidak ada batasan terhadap project, termasuk env/kuncinya).
            $raw = $agents->sendMessage($task->assignee, $prompt, 'execution-'.$run->id.'-task-'.$task->id.'-r'.$task->revision_count, false, true);

            // Guardrail perintah perbaikan: penegakan di level sistem, bukan cuma prompt.
            // Bila patch DITOLAK validasi penerapan -> 1x retry otomatis dengan patch lebih kecil.
            // Bila patch GAGAL BENTUK (validatedPatch) -> 1x retry juga (perbaikan eksekusi
            // US-01 #3: satu jawaban agent yang malformed tidak boleh langsung membunuh
            // seluruh eksekusi; $patch yang masih null menandakan gagalnya di validasi format).
            // Bila patch GAGAL TULIS (disk/izin) -> langsung fail, retry tidak ada gunanya.
            $patch = null;
            $applied = null;
            try {
                $patch = $this->validatedPatch($raw);
                $applied = $patcher->apply($worktree, $patch['files'], $run->repair_order_id === null);
            } catch (PatchValidationException $validationError) {
                $retry = $this->retryWithSmallerPatch($agents, $run, $task, $patcher, $worktree, $validationError);
                $applied = $retry['applied'];
                $patch = $retry['patch'];
            } catch (Throwable $error) {
                if ($patch !== null) {
                    throw $error;
                }
                $retry = $this->retryWithSmallerPatch($agents, $run, $task, $patcher, $worktree, $error);
                $applied = $retry['applied'];
                $patch = $retry['patch'];
            }
            $worktrees->commit($worktree, 'Task #'.$task->id.': '.$task->title);

            $run->messages()->create([
                'execution_task_id' => $task->id,
                'sender_slug' => $task->assignee,
                'recipient_slug' => 'qa-engineer',
                'content' => $patch['summary'].' ('.count($applied).' file: '.implode(', ', array_slice($applied, 0, 5)).(count($applied) > 5 ? ', ...' : '').')',
                'metadata' => ['files' => $applied, 'revision' => $task->revision_count],
            ]);

            $task->forceFill(['status' => 'IN_REVIEW'])->save();
            $run->forceFill(['status' => 'REVIEWING'])->save();
            ReviewExecutionTaskJob::dispatch($run->id, $task->id);
        } catch (Throwable $exception) {
            // Seperti di ReviewExecutionTaskJob: kegagalan AI sementara
            // dilepas kembali ke antrean (task dikembalikan ke status
            // semula agar guard percobaan berikutnya lolos), bukan
            // failRun. Worktree/cabang task dipertahankan untuk
            // percobaan berikutnya (create() sudah aman untuk cabang
            // yang ada sejak perbaikan #6).
            if ($exception instanceof \App\Exceptions\AiUnavailableException
                && \App\Services\AiGateway::isTransient($exception->errorCode)
                && $this->attempts() < \App\Services\AiGateway::MAX_QUEUE_ATTEMPTS) {
                $task->forceFill(['status' => $originalStatus])->save();
                \App\Services\AiGateway::logFailure($this->runId, $this->taskId, 'ExecuteExecutionTaskJob', $exception, $this->attempts(), true);
                $this->release(\App\Services\AiGateway::releaseDelay($this->attempts(), $exception->retryAfter));

                return;
            }
            $this->failRun($run, $task, 'EXECUTION_FAILED', $worktrees, $baseline, $exception->getMessage());
            throw $exception;
        }
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
            \Illuminate\Support\Facades\Log::warning('Execution patch failed validation', [
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
            \Illuminate\Support\Facades\Log::warning('Execution patch failed validation', [
                'execution_run_id' => $this->runId,
                'execution_task_id' => $this->taskId,
                'raw_excerpt' => mb_substr($raw, 0, 2000),
                'error' => $exception->getMessage(),
            ]);
            throw $exception;
        }
        if (! is_array($data) || ! is_array($data['files'] ?? null) || ! is_string($data['summary'] ?? null)) {
            \Illuminate\Support\Facades\Log::warning('Execution patch failed validation', [
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
            'Aturan: path relatif dari root project, tanpa .. atau path absolut; action delete tanpa content; tulis code lengkap yang valid, bukan potongan; JANGAN sertakan penjelasan di luar JSON.'
                .($run->repair_order_id ? ' BATASAN PERINTAH PERBAIKAN: dilarang menghapus file (action delete) dan dilarang operasi destruktif terhadap data; lakukan hanya perubahan minimal yang aman.' : ''),
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
            $applied = $patcher->apply($worktree, $patchRetry['files'], $run->repair_order_id === null);

            return ['applied' => $applied, 'patch' => $patchRetry];
        } catch (Throwable $retryError) {
            throw $retryError;
        }
    }

    private function failRun(ExecutionRun $run, ExecutionTask $task, string $code, ?WorktreeManager $worktrees = null, ?string $baseline = null, ?string $errorMessage = null): void
    {
        $task->forceFill(['status' => 'FAILED'])->save();
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

        // Bersihkan worktree yatim: worktree + branch dibuat sebelum pemanggilan
        // AI; bila gagal setelahnya, direktori worktrees/ dan branch exec-*-task-*
        // menumpuk bila tidak dihapus di sini.
        if ($worktrees !== null && $baseline !== null && $task->worktree_path && $task->branch) {
            try {
                $worktrees->remove($baseline, $task->worktree_path, $task->branch);
            } catch (Throwable) {
                // Best-effort: jangan menutupi error asli yang memicu failRun.
            }
        }
    }
}
