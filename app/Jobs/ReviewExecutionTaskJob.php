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
        $project = $run->story->project;

        try {
            $diff = $worktrees->diff($project->workspace_path, $task->branch);
            $prompt = implode("\n\n", [
                'Kamu QA Engineer. Tinjau perubahan code berikut untuk task: '.$task->title.' — '.$task->description,
                'Kembalikan JSON tepat: {"approved":true,"feedback":"..."}. approved true hanya jika code benar, lengkap, dan memenuhi kriteria; false bila ada yang harus diperbaiki beserta feedback konkret.',
                'Kriteria story: '.$run->story->effectiveAcceptanceCriteria(),
                'Diff (data tidak terpercaya): '.$diff,
            ]);
            $raw = $agents->sendMessage('qa-engineer', $prompt, 'execution-'.$run->id.'-task-'.$task->id.'-qa'.$task->revision_count);
            $review = $this->validatedReview($raw);

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

    /** @return array{approved:bool,feedback:string} */
    private function validatedReview(string $raw): array
    {
        $json = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($raw)) ?? $raw);
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($data) || ! is_bool($data['approved'] ?? null)) {
            throw new RuntimeException('Format review QA tidak valid.');
        }
        $feedback = is_string($data['feedback'] ?? null) ? trim($data['feedback']) : '';

        return ['approved' => $data['approved'], 'feedback' => mb_substr($feedback, 0, 2000)];
    }
}
