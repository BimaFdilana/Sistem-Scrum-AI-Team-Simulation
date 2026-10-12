<?php

namespace App\Jobs;

use App\Models\ExecutionRun;
use App\Models\ExecutionTask;
use App\Services\AgentProfileClient;
use App\Support\Workspace\PatchApplier;
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

    public int $tries = 1;

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
        $project = $run->story->project;
        $baseline = $project->workspace_path;
        if (! $baseline || ! is_dir($baseline)) {
            $this->failRun($run, $task, 'WORKSPACE_MISSING');

            return;
        }

        $task->forceFill(['status' => 'IN_PROGRESS'])->save();

        try {
            $worktrees->ensureRepo($baseline);
            $branch = 'exec-'.$run->id.'-task-'.$task->id;
            $worktree = $worktrees->create($baseline, $branch);
            $task->forceFill(['worktree_path' => $worktree, 'branch' => $branch])->save();

            $assigneeName = $task->assigneeName();
            $prompt = implode("\n\n", [
                "Kamu {$assigneeName}. Kerjakan task berikut pada project Laravel.",
                'Kembalikan JSON tepat: {"files":[{"path":"relatif/ke/file.php","action":"create|update|delete","content":"isi lengkap file"}],"summary":"ringkasan perubahan"}.',
                'Aturan: path relatif dari root project, tanpa .. atau path absolut; action delete tanpa content; maksimal '.PatchApplier::MAX_FILES.' file; tulis code lengkap yang valid, bukan potongan; JANGAN sertakan penjelasan di luar JSON.',
                $task->status === 'REVISION' && $task->qa_feedback
                    ? 'REVISI (putaran '.($task->revision_count + 1).'): perbaiki berdasarkan feedback QA berikut, lalu kirim ulang SEMUA file yang berubah: '.$task->qa_feedback
                    : 'Task: '.$task->title.' — '.$task->description,
                'Story: '.$run->story->title.' | Kriteria: '.$run->story->acceptance_criteria,
            ]);
            $raw = $agents->sendMessage($task->assignee, $prompt, 'execution-'.$run->id.'-task-'.$task->id.'-r'.$task->revision_count);
            $patch = $this->validatedPatch($raw);

            $applied = $patcher->apply($worktree, $patch['files']);
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
            $this->failRun($run, $task, 'EXECUTION_FAILED');
            throw $exception;
        }
    }

    /** @return array{files:array,summary:string} */
    private function validatedPatch(string $raw): array
    {
        if (strlen($raw) > 2000000) {
            throw new RuntimeException('Patch terlalu besar.');
        }
        // Agent kadang membungkus JSON dengan markdown fence; kupas bila ada.
        $json = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($raw)) ?? $raw);
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($data) || ! is_array($data['files'] ?? null) || ! is_string($data['summary'] ?? null)) {
            throw new RuntimeException('Format patch agent tidak valid.');
        }

        return ['files' => array_values($data['files']), 'summary' => trim($data['summary'])];
    }

    private function failRun(ExecutionRun $run, ExecutionTask $task, string $code): void
    {
        $task->forceFill(['status' => 'FAILED'])->save();
        $run->forceFill(['status' => 'FAILED', 'error_code' => $code, 'completed_at' => now()])->save();
    }
}
