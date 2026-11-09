<?php

namespace Tests\Feature;

use App\Jobs\BatchReviewExecutionJob;
use App\Jobs\ExecuteExecutionTaskJob;
use App\Models\ExecutionRun;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use App\Services\AgentProfileClient;
use App\Support\Execution\BatchQa;
use App\Support\Workspace\WorktreeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class BatchQaHonestyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['execution.qa_max_repair_rounds' => 1]);
    }

    private function makeRun(int $qaRounds = 0): ExecutionRun
    {
        $dir = storage_path('framework/testing/qa-'.uniqid());
        mkdir($dir, 0700, true);
        $project = Project::query()->create([
            'name' => 'QA', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel',
            'status' => 'BASELINE_READY', 'workspace_path' => $dir,
        ]);
        $story = Story::query()->create([
            'project_id' => $project->id, 'title' => 'S', 'estimate_points' => 3,
            'acceptance_criteria' => 'Tombol muncul setelah scroll.',
        ]);
        $run = ExecutionRun::query()->create([
            'story_id' => $story->id, 'requested_by' => User::factory()->create()->id,
            'status' => 'QA_REVIEW', 'mode' => 'direct', 'qa_rounds' => $qaRounds,
        ]);
        $run->tasks()->create([
            'title' => 'T', 'description' => 'D', 'assignee' => 'backend-developer', 'status' => 'QA_PENDING',
        ]);

        return $run;
    }

    /** @param array<int, string> $answers jawaban QA berurutan */
    private function runReview(ExecutionRun $run, array $answers): void
    {
        $agents = Mockery::mock(AgentProfileClient::class);
        $agents->shouldReceive('sendMessage')->andReturnValues($answers);
        $worktrees = Mockery::mock(WorktreeManager::class);
        $worktrees->shouldReceive('diff')->andReturn('diff');
        $worktrees->shouldReceive('changedFiles')->andReturn(['app/Foo.php']);
        $worktrees->shouldReceive('head')->andReturn(str_repeat('a', 40));

        (new BatchReviewExecutionJob($run->id))->handle($agents, $worktrees, app(BatchQa::class));
    }

    private const APPROVED = '{"approved":true,"feedback":"ok","criteria":[{"criterion":"Tombol muncul","met":true,"evidence":"resources/views/x.blade.php"}]}';

    private const REJECTED = '{"approved":false,"findings":[{"file":"app/Foo.php","instruction":"Perbaiki","verify":"cek"}]}';

    public function test_approval_with_evidence_completes_run(): void
    {
        Queue::fake();
        $run = $this->makeRun();

        $this->runReview($run, [self::APPROVED]);

        $this->assertSame('COMPLETED', $run->fresh()->status);
        $this->assertSame('APPROVED', $run->tasks()->first()->status);
    }

    public function test_bare_approval_without_criteria_is_not_accepted(): void
    {
        Queue::fake();
        $run = $this->makeRun();

        $this->runReview($run, ['{"approved":true,"feedback":"bagus"}', '{"approved":true,"feedback":"bagus"}']);

        $this->assertSame('NEEDS_PO', $run->fresh()->status);
        $this->assertSame('QA_BATCH_FORMAT', $run->fresh()->error_code);
        $this->assertSame('QA_PENDING', $run->tasks()->first()->status);
    }

    public function test_first_rejection_sends_task_back_for_repair(): void
    {
        Queue::fake();
        $run = $this->makeRun();

        $this->runReview($run, [self::REJECTED]);

        $this->assertSame('EXECUTING', $run->fresh()->status);
        $this->assertSame(1, (int) $run->fresh()->qa_rounds);
        $this->assertSame('REVISION', $run->tasks()->first()->status);
        Queue::assertPushed(ExecuteExecutionTaskJob::class);
    }

    public function test_rejection_after_repair_round_hands_over_instead_of_approving(): void
    {
        Queue::fake();
        $run = $this->makeRun(qaRounds: 1);

        $this->runReview($run, [self::REJECTED]);

        $fresh = $run->fresh();
        $this->assertSame('NEEDS_PO', $fresh->status);
        $this->assertSame('QA_REJECTED_AFTER_REPAIR', $fresh->error_code);
        $this->assertSame('QA_PENDING', $run->tasks()->first()->status);
        Queue::assertNotPushed(ExecuteExecutionTaskJob::class);
    }

    public function test_after_repair_qa_reviews_again_instead_of_auto_approving(): void
    {
        Queue::fake();
        $run = $this->makeRun(qaRounds: 1);
        $run->forceFill(['status' => 'EXECUTING'])->save();

        app(BatchQa::class)->maybeStart($run);

        $this->assertSame('QA_REVIEW', $run->fresh()->status);
        $this->assertSame('QA_PENDING', $run->tasks()->first()->status);
        Queue::assertPushed(BatchReviewExecutionJob::class);
    }

    public function test_resume_after_qa_rejection_gives_new_repair_budget_and_reruns_qa(): void
    {
        Queue::fake();
        $run = $this->makeRun(qaRounds: 1);
        $this->runReview($run, [self::REJECTED]);
        $this->assertSame('NEEDS_PO', $run->fresh()->status);

        $this->assertTrue($run->fresh()->resumeFromPo());

        $fresh = $run->fresh();
        $this->assertSame('QA_REVIEW', $fresh->status);
        $this->assertSame(0, (int) $fresh->qa_rounds);
        Queue::assertPushed(BatchReviewExecutionJob::class);
    }
}
