<?php

namespace Tests\Feature;

use App\Jobs\BatchReviewExecutionJob;
use App\Models\ExecutionRun;
use App\Models\PermissionRequest;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use App\Services\AgentProfileClient;
use App\Support\Execution\BatchQa;
use App\Support\Execution\TaskCompletion;
use App\Support\Workspace\WorktreeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class NoChangeTaskTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            parent::tearDown();
        }
    }

    private function makeRun(): ExecutionRun
    {
        $dir = storage_path('framework/testing/nc-'.uniqid());
        mkdir($dir.'/resources/views', 0700, true);
        file_put_contents($dir.'/resources/views/edit.blade.php', "@include('partials.password') {{-- MARKER_ISI_FILE --}}");
        $project = Project::query()->create(['name' => 'NC', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'BASELINE_READY', 'workspace_path' => $dir]);
        $story = Story::query()->create(['project_id' => $project->id, 'title' => 'S', 'estimate_points' => 3, 'acceptance_criteria' => 'Halaman memuat form password.']);
        $run = ExecutionRun::query()->create(['story_id' => $story->id, 'requested_by' => User::factory()->create()->id, 'status' => 'EXECUTING', 'mode' => 'direct', 'qa_mode' => 'batch']);
        $run->tasks()->create(['title' => 'Sertakan partial', 'description' => "KERJAKAN: include\nFILE: resources/views/edit.blade.php", 'assignee' => 'frontend-developer', 'status' => 'IN_PROGRESS']);
        PermissionRequest::query()->create([
            'execution_run_id' => $run->id, 'title' => 'Izin', 'status' => 'APPROVED', 'requested_by' => $run->requested_by,
            'payload' => ['tasks' => [['title' => 'Sertakan partial', 'assignee' => 'frontend-developer', 'files' => ['resources/views/edit.blade.php']]]],
        ]);

        return $run;
    }

    public function test_agent_finishing_without_file_changes_goes_to_qa_instead_of_failing_the_run(): void
    {
        Queue::fake();
        $run = $this->makeRun();
        $task = $run->tasks()->first();

        app(TaskCompletion::class)->submitNoChange($run, $task, 'worker kanban menandai selesai.');

        $this->assertSame('QA_PENDING', $task->fresh()->status);
        $this->assertSame('QA_REVIEW', $run->fresh()->status);
        $this->assertTrue(TaskCompletion::isNoChange($run, $task->fresh()));
        Queue::assertPushed(BatchReviewExecutionJob::class);
    }

    public function test_qa_sees_the_target_file_content_for_a_task_without_changes(): void
    {
        Queue::fake();
        $run = $this->makeRun();
        $task = $run->tasks()->first();
        app(TaskCompletion::class)->submitNoChange($run, $task, 'sudah ada.');
        $run->refresh();

        $prompts = [];
        $agents = Mockery::mock(AgentProfileClient::class);
        $agents->shouldReceive('sendMessage')->andReturnUsing(function (string $slug, string $prompt) use (&$prompts) {
            $prompts[] = $prompt;

            return '{"approved":true,"criteria":[{"criterion":"Halaman memuat form password.","met":true,"evidence":"edit.blade.php memuat @include partials.password"}]}';
        });
        $worktrees = Mockery::mock(WorktreeManager::class);
        $worktrees->shouldReceive('diff')->andReturn('');
        $worktrees->shouldReceive('changedFiles')->andReturn([]);
        $worktrees->shouldReceive('head')->andReturn(str_repeat('a', 40));

        (new BatchReviewExecutionJob($run->id))->handle($agents, $worktrees, app(BatchQa::class));

        $this->assertStringContainsString('TANPA PERUBAHAN', $prompts[0]);
        $this->assertStringContainsString('MARKER_ISI_FILE', $prompts[0], 'isi file target harus ada di konteks QA');
        $this->assertSame('COMPLETED', $run->fresh()->status);
    }
}
