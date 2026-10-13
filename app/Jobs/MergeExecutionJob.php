<?php

namespace App\Jobs;

use App\Models\ChatConversation;
use App\Models\ExecutionRun;
use App\Services\ChatDisclosurePolicy;
use App\Services\HermesClient;
use App\Support\Agents\AgentSoul;
use App\Support\Workspace\WorktreeManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Merge semua task yang disetujui QA ke baseline, bersihkan worktree,
 * lalu Scrum Master merangkum progress ke PO via chat.
 */
class MergeExecutionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $runId) {}

    public function handle(
        WorktreeManager $worktrees,
        HermesClient $scrumMaster,
        ChatDisclosurePolicy $disclosure,
    ): void {
        $run = ExecutionRun::query()->with(['tasks', 'story.project', 'repairOrder.project'])->find($this->runId);
        if (! $run || $run->status !== 'MERGING') {
            return;
        }
        $baseline = $run->project()?->workspace_path;
        if (! $baseline) {
            $run->forceFill(['status' => 'BLOCKED', 'error_code' => 'WORKSPACE_MISSING', 'completed_at' => now()])->save();

            return;
        }

        try {
            foreach ($run->tasks as $task) {
                if ($task->status !== 'APPROVED' || ! $task->branch) {
                    throw new RuntimeException('Task #'.$task->id.' belum disetujui QA.');
                }
                $worktrees->merge($baseline, $task->branch);
            }
            foreach ($run->tasks as $task) {
                if ($task->worktree_path && $task->branch) {
                    $worktrees->remove($baseline, $task->worktree_path, $task->branch);
                }
            }

            $summary = $this->summarize($run, $scrumMaster, $disclosure);
            $run->forceFill([
                'status' => 'COMPLETED',
                'summary' => $summary,
                'completed_at' => now(),
            ])->save();
            $run->messages()->create([
                'sender_slug' => 'scrum-master',
                'recipient_slug' => 'po',
                'content' => $summary,
            ]);
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => 'BLOCKED',
                'error_code' => 'MERGE_CONFLICT',
                'summary' => 'Merge gagal: '.$exception->getMessage(),
                'completed_at' => now(),
            ])->save();
            throw $exception;
        }
    }

    private function summarize(ExecutionRun $run, HermesClient $scrumMaster, ChatDisclosurePolicy $disclosure): string
    {
        $taskLines = $run->tasks->map(fn ($task) => '- '.$task->title.' ('.$task->assigneeName().', revisi QA: '.$task->revision_count.')')->implode("\n");
        $conversation = ChatConversation::query()->where('user_id', $run->requested_by)
            ->where('agent_profile', 'scrum-master')->latest('updated_at')->first();
        if (! $conversation) {
            $conversation = ChatConversation::query()->create([
                'user_id' => $run->requested_by,
                'uuid' => (string) Str::uuid(),
                'title' => 'Eksekusi '.$run->subjectLabel(),
                'agent_profile' => 'scrum-master',
            ]);
        }
        $prompt = AgentSoul::promptLine('scrum-master')."\n\n"
            .'Sebagai Scrum Master, buat ringkasan progress eksekusi untuk PO. Singkat, tanpa informasi internal.'
            ."\nSubjek: ".$run->subjectLabel()
            ."\nTask selesai:\n".$taskLines;
        try {
            $text = trim($scrumMaster->sendMessage($prompt, $conversation->uuid));
            if ($text === '' || mb_strlen($text) > 6000
                || $text === ChatDisclosurePolicy::SAFE_RESPONSE
                || $disclosure->containsSensitiveDisclosure($text)) {
                throw new RuntimeException('Invalid summary.');
            }
            $conversation->messages()->create([
                'role' => 'assistant',
                'content' => 'Eksekusi '.$run->subjectLabel()." selesai.\n".$text,
            ]);
            $conversation->touch();

            return $text;
        } catch (Throwable) {
            return 'Eksekusi selesai: '.count($run->tasks).' task disetujui QA dan di-merge ke baseline.';
        }
    }
}
