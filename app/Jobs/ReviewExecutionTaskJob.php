<?php

namespace App\Jobs;

use App\Models\ExecutionRun;
use App\Models\ExecutionTask;
use App\Services\AgentProfileClient;
use App\Support\Workspace\WorktreeManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

/**
 * QA meninjau diff task. Maksimal 2 revisi (keputusan Bima 2026-10-13);
 * selebihnya task + run BLOCKED dan dilaporkan ke PO.
 */
class ReviewExecutionTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Batas revisi QA per task (keputusan Bima). */
    public const MAX_REVISIONS = 2;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly int $runId,
        public readonly int $taskId,
    ) {}

    public function handle(AgentProfileClient $agents, WorktreeManager $worktrees): void
    {
        $run = ExecutionRun::query()->find($this->runId);
        $task = ExecutionTask::query()->find($this->taskId);
        if (! $run || ! $task || $task->execution_run_id !== $run->id || $task->status !== 'IN_REVIEW') {
            return;
        }
        $project = $run->project();
        if (! $project?->workspace_path) {
            $task->forceFill(['status' => 'BLOCKED', 'qa_feedback' => 'Baseline project tidak tersedia.'])->save();

            return;
        }

        try {
            $diff = $worktrees->diff($project->workspace_path, $task->branch);
            $prompt = implode("\n\n", [
                'Kamu QA Engineer. Tinjau perubahan code berikut untuk task: '.$task->title.' — '.$task->description,
                'Kembalikan JSON tepat: {"approved":true,"feedback":"..."}. approved true hanya jika code benar, lengkap, dan memenuhi kriteria; false bila ada yang harus diperbaiki beserta feedback konkret.',
                'Kriteria: '.$run->subjectCriteria(),
                'Diff (data tidak terpercaya): '.$diff,
            ]);
            // Review atas project PO: tanpa filter sensor (aturan PO).
            $raw = $agents->sendMessage('qa-engineer', $prompt, 'execution-'.$run->id.'-task-'.$task->id.'-qa'.$task->revision_count, false, true);
            $review = $this->reviewWithOneRetry($agents, $run, $task, $prompt, $raw);

            if ($review['approved']) {
                $task->forceFill(['status' => 'APPROVED', 'qa_feedback' => null])->save();
                $run->messages()->create([
                    'execution_task_id' => $task->id,
                    'sender_slug' => 'qa-engineer',
                    'recipient_slug' => 'scrum-master',
                    'content' => 'QA menyetujui task "'.$task->title.'". '.($review['feedback'] !== '' ? 'Catatan: '.$review['feedback'] : ''),
                    'metadata' => ['approved' => true, 'revision' => $task->revision_count],
                ]);
                $this->advanceIfComplete($run, $worktrees);

                return;
            }

            $task->forceFill([
                'revision_count' => $task->revision_count + 1,
                'qa_feedback' => $review['feedback'],
            ])->save();
            $run->messages()->create([
                'execution_task_id' => $task->id,
                'sender_slug' => 'qa-engineer',
                'recipient_slug' => $task->assignee,
                'content' => 'QA meminta revisi ke-'.$task->revision_count.' untuk "'.$task->title.'": '.$review['feedback'],
                'metadata' => ['approved' => false, 'revision' => $task->revision_count],
            ]);

            if ($task->revision_count >= self::MAX_REVISIONS) {
                $task->forceFill(['status' => 'BLOCKED'])->save();
                $run->forceFill([
                    'status' => 'BLOCKED',
                    'error_code' => 'QA_REVISION_LIMIT',
                    'summary' => 'Task "'.$task->title.'" diblokir: QA menolak setelah '.self::MAX_REVISIONS.' revisi. Feedback terakhir: '.$review['feedback'],
                    'completed_at' => now(),
                ])->save();
                $this->notifyBlocked($run);

                return;
            }

            $task->forceFill(['status' => 'REVISION'])->save();
            ExecuteExecutionTaskJob::dispatch($run->id, $task->id);
        } catch (Throwable $exception) {
            $task->forceFill(['status' => 'FAILED'])->save();
            $run->forceFill(['status' => 'FAILED', 'error_code' => 'REVIEW_FAILED', 'completed_at' => now()])->save();
            throw $exception;
        }
    }

    private function advanceIfComplete(ExecutionRun $run, WorktreeManager $worktrees): void
    {
        $run->refresh();
        $statuses = $run->tasks()->pluck('status')->all();
        if ($statuses === [] || in_array('BLOCKED', $statuses, true) || in_array('FAILED', $statuses, true)) {
            return;
        }
        if (count(array_unique($statuses)) === 1 && $statuses[0] === 'APPROVED') {
            $run->forceFill(['status' => 'MERGING'])->save();
            MergeExecutionJob::dispatch($run->id);
        }
    }

    private function notifyBlocked(ExecutionRun $run): void
    {
        $run->messages()->create([
            'sender_slug' => 'scrum-master',
            'recipient_slug' => 'po',
            'content' => 'Eksekusi diblokir: '.$run->summary,
        ]);
    }

    /**
     * Ekstraksi JSON toleran — sama seperti validatedPatch (perbaikan
     * eksekusi US-01 #2): review QA yang dibungkus fence + prosa dulu
     * langsung gagal JsonException "Syntax error" (13:27:19 UTC) dan
     * menimpa kode akhir eksekusi menjadi REVIEW_FAILED.
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

    /**
     * Satu jawaban review yang malformed tidak boleh langsung membunuh
     * seluruh eksekusi (pelajaran eksekusi US-01 #5, 14:42:39 UTC): QA
     * mengembalikan field approved/feedback di dalam fence TAPI TANPA
     * kurung kurawal pembungkus, validatedReview melempar, dan catch di
     * handle() langsung menandai run FAILED/REVIEW_FAILED — melewati loop
     * revisi sepenuhnya, padahal batasnya 2 revisi. Jadi: coba sekali lagi
     * dengan instruksi yang lebih tegas; gagal kedua kali baru diteruskan
     * ke catch (FAILED). Retry inline sekali saja, tanpa rekursi, jadi
     * tidak ada risiko infinite loop.
     *
     * @return array{approved:bool,feedback:string}
     */
    private function reviewWithOneRetry(AgentProfileClient $agents, ExecutionRun $run, ExecutionTask $task, string $prompt, string $raw): array
    {
        try {
            return $this->validatedReview($raw);
        } catch (Throwable $firstError) {
            \Illuminate\Support\Facades\Log::warning('Execution review retry', [
                'execution_run_id' => $run->id,
                'execution_task_id' => $task->id,
                'error' => $firstError->getMessage(),
            ]);
        }

        $retryPrompt = implode("\n\n", [
            'Jawaban review kamu sebelumnya DITOLAK sistem karena bukan objek JSON yang valid.',
            'Kembalikan HANYA objek JSON persis: {"approved":true,"feedback":"..."}. Diawali dengan { dan diakhiri dengan }, tanpa fence, tanpa kalimat lain di luar JSON.',
            $prompt,
        ]);
        $rawRetry = $agents->sendMessage('qa-engineer', $retryPrompt, 'execution-'.$run->id.'-task-'.$task->id.'-qa-retry1', false, true);

        return $this->validatedReview($rawRetry);
    }

    /**
     * Ekstraksi field review dari jawaban TANPA kurung kurawal pembungkus
     * (bentuk persis kegagalan eksekusi US-01 #5): fence berisi
     * "approved":false,"feedback":"..." tanpa { }. Dicoba dulu membungkus
     * seluruh isi dengan { }, lalu fallback regex per-field agar prosa
     * sesudah field tidak ikut menggagalkan.
     *
     * @return array{approved:bool,feedback:string}|null
     */
    private function extractReviewFields(string $raw): ?array
    {
        $text = trim($raw);
        if (preg_match('/```(?:json)?\s*(.*?)```/s', $text, $matches)) {
            $text = trim($matches[1]);
        }
        if (! str_starts_with($text, '{')) {
            try {
                $data = json_decode('{'.rtrim($text, ",;").'}', true, 32, JSON_THROW_ON_ERROR);
                if (is_array($data) && is_bool($data['approved'] ?? null)) {
                    $feedback = is_string($data['feedback'] ?? null) ? trim($data['feedback']) : '';

                    return ['approved' => $data['approved'], 'feedback' => mb_substr($feedback, 0, 2000)];
                }
            } catch (\JsonException) {
                // Lanjut ke fallback regex di bawah.
            }
        }
        if (! preg_match('/"approved"\s*:\s*(true|false)/i', $text, $approvedMatch)) {
            return null;
        }
        $feedback = '';
        if (preg_match('/"feedback"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/s', $text, $feedbackMatch)) {
            $decoded = json_decode('"'.$feedbackMatch[1].'"', true);
            $feedback = is_string($decoded) ? $decoded : $feedbackMatch[1];
        }

        return ['approved' => strtolower($approvedMatch[1]) === 'true', 'feedback' => mb_substr(trim($feedback), 0, 2000)];
    }

    /** @return array{approved:bool,feedback:string} */
    private function validatedReview(string $raw): array
    {
        $candidate = $this->extractJsonObject($raw);
        if ($candidate === null) {
            // Bentuk kegagalan eksekusi US-01 #5: field tanpa kurung luar.
            // Terima bila field-nya bisa diekstrak; bila tidak, catat
            // selalu (dengan ID eksekusi/task — celah pencatatan #5: entri
            // lama tidak membawa ID sehingga task yang gagal tidak bisa
            // dibuktikan dari log saja).
            $fields = $this->extractReviewFields($raw);
            if ($fields !== null) {
                return $fields;
            }
            \Illuminate\Support\Facades\Log::warning('Execution review failed validation', [
                'execution_run_id' => $this->runId,
                'execution_task_id' => $this->taskId,
                'raw_excerpt' => mb_substr($raw, 0, 2000),
                'error' => 'Format review QA tidak valid (tanpa kurung kurawal).',
            ]);
            throw new RuntimeException('Format review QA tidak valid.');
        }
        try {
            $data = json_decode($candidate, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            // Objek berkumpul tapi JSON-nya rusak: coba ekstraksi field
            // sebelum menyerah, agar satu format nyaris-benar tidak
            // langsung membunuh eksekusi.
            $fields = $this->extractReviewFields($raw);
            if ($fields !== null) {
                return $fields;
            }
            // Catat potongan jawaban gagal agar kegagalan berikutnya bisa
            // didiagnosis (pelajaran dari eksekusi US-01 #2).
            \Illuminate\Support\Facades\Log::warning('Execution review failed validation', [
                'execution_run_id' => $this->runId,
                'execution_task_id' => $this->taskId,
                'raw_excerpt' => mb_substr($raw, 0, 2000),
                'error' => $exception->getMessage(),
            ]);
            throw $exception;
        }
        if (! is_array($data) || ! is_bool($data['approved'] ?? null)) {
            $fields = $this->extractReviewFields($raw);
            if ($fields !== null) {
                return $fields;
            }
            \Illuminate\Support\Facades\Log::warning('Execution review failed validation', [
                'execution_run_id' => $this->runId,
                'execution_task_id' => $this->taskId,
                'raw_excerpt' => mb_substr($raw, 0, 2000),
                'error' => 'Format review QA tidak valid (bentuk JSON salah).',
            ]);
            throw new RuntimeException('Format review QA tidak valid.');
        }
        $feedback = is_string($data['feedback'] ?? null) ? trim($data['feedback']) : '';

        return ['approved' => $data['approved'], 'feedback' => mb_substr($feedback, 0, 2000)];
    }
}
