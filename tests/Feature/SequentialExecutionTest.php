<?php

namespace Tests\Feature;

use App\Jobs\BreakdownExecutionJob;
use App\Jobs\ExecuteExecutionTaskJob;
use App\Jobs\MergeExecutionJob;
use App\Jobs\ReviewExecutionTaskJob;
use App\Models\ExecutionRun;
use App\Models\PermissionRequest;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use App\Services\AgentProfileClient;
use App\Services\AgentToolRunner;
use App\Services\ChatDisclosurePolicy;
use App\Services\HermesClient;
use App\Support\Workspace\WorktreeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Model eksekusi berurutan + sinkron (keputusan Bima 2026-10-31):
 * breakdown berurutan, dispatch satu-per-satu setelah QA-APPROVED,
 * worktree berantai, diff QA per task, merge sesuai urutan.
 */
class SequentialExecutionTest extends TestCase
{
    use RefreshDatabase;

    private function makeProjectWithStory(string $workspace): array
    {
        $owner = User::factory()->create();
        $project = Project::query()->create([
            'name' => 'Seq', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel',
            'status' => 'BASELINE_READY', 'workspace_path' => $workspace,
        ]);
        $story = Story::query()->create([
            'project_id' => $project->id, 'title' => 'S', 'estimate_points' => 5,
        ]);

        return [$owner, $project, $story];
    }

    private function makeBaseline(string $name): array
    {
        $baseline = storage_path('framework/testing/'.$name.'-'.uniqid());
        mkdir($baseline, 0700, true);
        file_put_contents($baseline.'/base.txt', 'baseline');
        $worktrees = new WorktreeManager;
        $worktrees->ensureRepo($baseline);

        return [$baseline, $worktrees];
    }

    public function test_breakdown_stores_tasks_in_execution_order(): void
    {
        [$baseline] = $this->makeBaseline('seq-breakdown');
        [$owner, $project, $story] = $this->makeProjectWithStory($baseline);
        $run = ExecutionRun::query()->create([
            'story_id' => $story->id, 'requested_by' => $owner->id, 'status' => 'QUEUED',
        ]);
        $fake = Mockery::mock(HermesClient::class);
        $fake->shouldReceive('sendMessage')->once()->andReturn(json_encode(['tasks' => [
            ['title' => 'Partial dulu', 'description' => 'Buat partial.', 'assignee' => 'backend-developer', 'files' => []],
            ['title' => 'Baru styling', 'description' => 'Pakai partial task sebelumnya.', 'assignee' => 'frontend-developer', 'files' => []],
        ]]));
        (new BreakdownExecutionJob($run->id))->handle($fake, app(ChatDisclosurePolicy::class));

        $ordered = $run->fresh()->orderedTasks()->get();
        $this->assertSame(['Partial dulu', 'Baru styling'], $ordered->pluck('title')->all());
        $this->assertSame([0, 1], $ordered->pluck('sort_order')->all());
    }

    public function test_approve_dispatches_only_first_pending_task(): void
    {
        Queue::fake();
        [$baseline] = $this->makeBaseline('seq-approve');
        [$owner, $project, $story] = $this->makeProjectWithStory($baseline);
        $run = ExecutionRun::query()->create([
            'story_id' => $story->id, 'requested_by' => $owner->id, 'status' => 'AWAITING_PERMISSION',
        ]);
        $first = $run->tasks()->create([
            'title' => 'T1', 'description' => 'D1', 'assignee' => 'backend-developer',
            'status' => 'PENDING', 'sort_order' => 0,
        ]);
        $second = $run->tasks()->create([
            'title' => 'T2', 'description' => 'D2', 'assignee' => 'frontend-developer',
            'status' => 'PENDING', 'sort_order' => 1,
        ]);
        $permission = PermissionRequest::query()->create([
            'execution_run_id' => $run->id, 'title' => 'Izin', 'status' => 'PENDING',
            'requested_by' => $owner->id,
        ]);

        $this->actingAs($owner)->post("/accepted/{$permission->id}/approve")
            ->assertRedirect()->assertSessionHas('status');

        Queue::assertPushed(ExecuteExecutionTaskJob::class, fn ($job) => $job->taskId === $first->id);
        Queue::assertPushed(ExecuteExecutionTaskJob::class, 1);
        $this->assertSame('PENDING', $second->fresh()->status);
    }

    public function test_qa_approval_advances_to_next_pending_task_instead_of_merging(): void
    {
        Queue::fake();
        [$baseline, $worktrees] = $this->makeBaseline('seq-advance');
        [$owner, $project, $story] = $this->makeProjectWithStory($baseline);
        $run = ExecutionRun::query()->create([
            'story_id' => $story->id, 'requested_by' => $owner->id, 'status' => 'REVIEWING',
        ]);
        $wt = $worktrees->create($baseline, 'wt-seq-advance-1');
        file_put_contents($wt.'/note.txt', 'hasil task pertama');
        $worktrees->commit($wt, 'Task pertama');
        $first = $run->tasks()->create([
            'title' => 'T1', 'description' => 'D1', 'assignee' => 'backend-developer',
            'status' => 'IN_REVIEW', 'sort_order' => 0,
            'worktree_path' => $wt, 'branch' => 'wt-seq-advance-1',
        ]);
        $second = $run->tasks()->create([
            'title' => 'T2', 'description' => 'D2', 'assignee' => 'frontend-developer',
            'status' => 'PENDING', 'sort_order' => 1,
        ]);

        $agents = Mockery::mock(AgentProfileClient::class);
        $agents->shouldReceive('toolModel')->with('qa-engineer')->andReturn(null);
        $agents->shouldReceive('sendMessage')->andReturn('{"approved":true,"feedback":"ok"}');
        (new ReviewExecutionTaskJob($run->id, $first->id))->handle($agents, $worktrees);

        $this->assertSame('APPROVED', $first->fresh()->status);
        Queue::assertPushed(ExecuteExecutionTaskJob::class, fn ($job) => $job->taskId === $second->id);
        Queue::assertNotPushed(MergeExecutionJob::class);
    }

    public function test_chained_task_sees_previous_files_and_diff_is_per_task(): void
    {
        Queue::fake();
        config(['ai.execution_driver' => 'internal']);
        [$baseline, $worktrees] = $this->makeBaseline('seq-chain');
        [$owner, $project, $story] = $this->makeProjectWithStory($baseline);
        $run = ExecutionRun::query()->create([
            'story_id' => $story->id, 'requested_by' => $owner->id, 'status' => 'EXECUTING',
        ]);
        // Task pertama: sudah APPROVED, cabangnya berisi file1.
        $prevWt = $worktrees->create($baseline, 'wt-seq-chain-prev');
        file_put_contents($prevWt.'/file1.txt', 'dari task pertama');
        $worktrees->commit($prevWt, 'Task pertama');
        $first = $run->tasks()->create([
            'title' => 'Partial tombol', 'description' => 'Buat partial.', 'assignee' => 'backend-developer',
            'status' => 'APPROVED', 'sort_order' => 0,
            'worktree_path' => $prevWt, 'branch' => 'wt-seq-chain-prev',
        ]);
        $run->messages()->create([
            'execution_task_id' => $first->id, 'sender_slug' => 'backend-developer',
            'recipient_slug' => 'qa-engineer',
            'content' => 'Membuat partial tombol (1 file: file1.txt)',
            'metadata' => ['files' => ['file1.txt']],
        ]);
        $second = $run->tasks()->create([
            'title' => 'Konfigurasi ambang', 'description' => 'Pakai partial.', 'assignee' => 'backend-developer',
            'status' => 'PENDING', 'sort_order' => 1,
        ]);

        $capturedPrompt = null;
        $runner = Mockery::mock(AgentToolRunner::class);
        $runner->shouldReceive('run')->once()->withArgs(function ($slug, $worktree, $prompt) use (&$capturedPrompt) {
            $capturedPrompt = $prompt;
            file_put_contents($worktree.'/file2.txt', 'dari task kedua');

            return true;
        })->andReturn(['files' => ['file2.txt'], 'summary' => 'Menambah konfigurasi ambang']);
        (new ExecuteExecutionTaskJob($run->id, $second->id))->handle($worktrees, $runner);

        $second = $second->fresh();
        $this->assertSame('IN_REVIEW', $second->status);
        // Worktree berantai: file task pertama terlihat di worktree task kedua.
        $this->assertSame('wt-seq-chain-prev', $second->chain_base);
        $this->assertSame('dari task pertama', file_get_contents($second->worktree_path.'/file1.txt'));
        // Konteks task sebelumnya masuk prompt (ringkasan + file, bukan diff).
        $this->assertStringContainsString('Membuat partial tombol', $capturedPrompt);
        $this->assertStringContainsString('file1.txt', $capturedPrompt);
        // Diff QA per task: hanya file task kedua, bukan file task pertama.
        $this->assertStringContainsString('file2.txt', $second->diff_snapshot);
        $this->assertStringNotContainsString('file1.txt', $second->diff_snapshot);
    }

    public function test_chained_branches_merge_in_order_without_duplication(): void
    {
        [$baseline, $worktrees] = $this->makeBaseline('seq-merge');
        $wtA = $worktrees->create($baseline, 'wt-seq-merge-a');
        file_put_contents($wtA.'/a.txt', 'dari cabang A');
        $worktrees->commit($wtA, 'Cabang A');
        $wtB = $worktrees->create($baseline, 'wt-seq-merge-b', 'wt-seq-merge-a');
        file_put_contents($wtB.'/b.txt', 'dari cabang B');
        $worktrees->commit($wtB, 'Cabang B');

        // Diff per task terhadap chain base-nya.
        $this->assertSame(['b.txt'], $worktrees->changedFiles($baseline, 'wt-seq-merge-b', 'wt-seq-merge-a'));
        $this->assertStringContainsString('b.txt', $worktrees->diff($baseline, 'wt-seq-merge-b', 'wt-seq-merge-a'));
        $this->assertStringNotContainsString('a.txt', $worktrees->diff($baseline, 'wt-seq-merge-b', 'wt-seq-merge-a'));

        // Merge berurutan: cabang A dulu, lalu B — B tidak konflik dan
        // tidak menggandakan hasil A.
        $worktrees->merge($baseline, 'wt-seq-merge-a');
        $worktrees->merge($baseline, 'wt-seq-merge-b');
        $this->assertSame('dari cabang A', file_get_contents($baseline.'/a.txt'));
        $this->assertSame('dari cabang B', file_get_contents($baseline.'/b.txt'));
    }
}
