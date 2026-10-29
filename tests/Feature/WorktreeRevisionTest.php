<?php

namespace Tests\Feature;

use App\Jobs\ExecuteExecutionTaskJob;
use App\Jobs\ReviewExecutionTaskJob;
use App\Models\ExecutionRun;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use App\Support\Workspace\PatchApplier;
use App\Support\Workspace\WorktreeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WorktreeRevisionTest extends TestCase
{
    use RefreshDatabase;

    private function makeBaseline(): array
    {
        $baseline = storage_path('framework/testing/worktree-revision-'.uniqid());
        mkdir($baseline, 0700, true);
        file_put_contents($baseline.'/README.md', 'baseline');
        $worktrees = new WorktreeManager;
        $worktrees->ensureRepo($baseline);

        return [$baseline, $worktrees];
    }

    public function test_create_twice_reuses_registered_worktree(): void
    {
        // Regresi eksekusi US-01 #6: create() kedua dengan nama cabang
        // yang sama dulu fatal "a branch named ... already exists".
        [$baseline, $worktrees] = $this->makeBaseline();
        $first = $worktrees->create($baseline, 'exec-9-task-1');
        file_put_contents($first.'/draft.txt', 'belum di-commit');

        $second = $worktrees->create($baseline, 'exec-9-task-1');

        $this->assertSame($first, $second);
        $this->assertSame('belum di-commit', file_get_contents($second.'/draft.txt'));
    }

    public function test_create_after_worktree_dir_deleted_readds_existing_branch(): void
    {
        // Keadaan persis sesudah kegagalan #6: direktori worktree terhapus
        // (create lama menghapusnya sebelum `add -b` gagal) sementara
        // cabang tetap ada — create berikutnya harus mendaftar ulang
        // cabang itu tanpa -b, bukan gagal lagi.
        [$baseline, $worktrees] = $this->makeBaseline();
        $first = $worktrees->create($baseline, 'exec-9-task-2');
        file_put_contents($first.'/kept.txt', 'dari commit cabang');
        $worktrees->commit($first, 'Commit cabang task');
        File::deleteDirectory($first);

        $second = $worktrees->create($baseline, 'exec-9-task-2');

        $this->assertSame($first, $second);
        $this->assertSame('dari commit cabang', file_get_contents($second.'/kept.txt'));
    }

    public function test_revision_rework_reuses_task_worktree(): void
    {
        // End-to-end regresi #6: pengerjaan pertama -> IN_REVIEW, QA
        // meminta revisi, pengerjaan ulang harus kembali IN_REVIEW (bukan
        // EXECUTION_FAILED karena tabrakan nama cabang), dan file dari
        // pengerjaan pertama tetap ada di worktree.
        Queue::fake();
        [$baseline, $worktrees] = $this->makeBaseline();
        $owner = User::factory()->create();
        $project = Project::query()->create([
            'name' => 'PR', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel',
            'status' => 'BASELINE_READY', 'workspace_path' => $baseline,
        ]);
        $story = Story::query()->create([
            'project_id' => $project->id, 'title' => 'S', 'estimate_points' => 5,
        ]);
        $run = ExecutionRun::query()->create([
            'story_id' => $story->id, 'requested_by' => $owner->id, 'status' => 'EXECUTING',
        ]);
        $task = $run->tasks()->create([
            'title' => 'T1', 'description' => 'D1', 'assignee' => 'backend-developer', 'status' => 'PENDING',
        ]);

        $this->mock(\App\Services\AgentProfileClient::class, function ($mock): void {
            $mock->shouldReceive('sendMessage')->andReturn(
                json_encode(['files' => [['path' => 'satu.css', 'action' => 'create', 'content' => '.a{}']], 'summary' => 'Satu.']),
                json_encode(['files' => [['path' => 'dua.css', 'action' => 'create', 'content' => '.b{}']], 'summary' => 'Dua.']),
            );
        });
        $agents = app(\App\Services\AgentProfileClient::class);

        (new ExecuteExecutionTaskJob($run->id, $task->id))->handle($agents, $worktrees, app(PatchApplier::class));
        $this->assertSame('IN_REVIEW', $task->fresh()->status);

        // Simulasi QA meminta revisi (seperti ReviewExecutionTaskJob).
        $task->forceFill(['status' => 'REVISION', 'qa_feedback' => 'Perbaiki.'])->save();
        $run->forceFill(['status' => 'REVIEWING'])->save();

        (new ExecuteExecutionTaskJob($run->id, $task->id))->handle($agents, $worktrees, app(PatchApplier::class));

        $this->assertSame('IN_REVIEW', $task->fresh()->status);
        $worktree = $task->fresh()->worktree_path;
        $this->assertSame('.a{}', file_get_contents($worktree.'/satu.css'));
        $this->assertSame('.b{}', file_get_contents($worktree.'/dua.css'));
        Queue::assertPushed(ReviewExecutionTaskJob::class);
    }
}
