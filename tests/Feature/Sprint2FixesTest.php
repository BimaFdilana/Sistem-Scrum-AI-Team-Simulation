<?php

namespace Tests\Feature;

use App\Jobs\AdvanceStoryEstimationJob;
use App\Models\ExecutionRun;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Story;
use App\Models\StoryEstimationRun;
use App\Models\User;
use App\Support\Execution\DirectWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Perbaikan Sprint 2: baseline kotor tidak lagi menahan eksekusi, sprint bisa
 * dibuka kuncinya, PO bisa meminta estimasi ulang, dan ubah kode langsung dari chat.
 */
class Sprint2FixesTest extends TestCase
{
    use RefreshDatabase;

    private string $repo;

    private function git(string ...$args): string
    {
        $process = new Process(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', ...$args], $this->repo);
        $process->mustRun();

        return trim($process->getOutput());
    }

    private function project(): Project
    {
        $this->repo = storage_path('framework/testing/s2-'.uniqid());
        mkdir($this->repo, 0700, true);
        $this->git('init', '-q', '-b', 'main');
        file_put_contents($this->repo.'/app.php', "<?php\necho 'awal';\n");
        $this->git('add', '.');
        $this->git('commit', '-q', '-m', 'awal');

        return Project::query()->create([
            'name' => 'S2', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'BASELINE_READY', 'workspace_path' => $this->repo,
        ]);
    }

    private function sprint(Project $project, string $status = 'PLANNING'): Sprint
    {
        $backlog = $project->backlog()->firstOrCreate([], ['name' => 'S2 Backlog']);

        return Sprint::create(['backlog_id' => $backlog->id, 'project_id' => $project->id, 'name' => 'Sprint 2', 'status' => $status, 'revision' => 1, 'scope_approved_revision' => $status === 'ACTIVE' ? 1 : null]);
    }

    private function makeRun(Project $project, string $status, string $branch): ExecutionRun
    {
        $story = Story::create(['project_id' => $project->id, 'title' => 'Pagu', 'estimate_points' => 5]);

        return ExecutionRun::query()->create([
            'story_id' => $story->id, 'requested_by' => User::factory()->create()->id, 'status' => $status,
            'mode' => 'direct', 'work_branch' => $branch, 'base_branch' => 'main',
        ]);
    }

    // ---------- 1. Baseline kotor (akar masalah Sprint 2) ----------

    public function test_leftovers_of_a_failed_run_are_saved_on_its_branch_and_baseline_is_clean(): void
    {
        $project = $this->project();
        $this->git('checkout', '-q', '-b', 'scrum/run-10');
        $run = $this->makeRun($project, 'FAILED', 'scrum/run-10');
        file_put_contents($this->repo.'/app.php', "<?php\necho 'setengah jadi';\n");

        $note = app(DirectWorkspace::class)->recoverLeftovers($project);

        $this->assertStringContainsString('scrum/run-10', (string) $note);
        $this->assertSame('main', $this->git('branch', '--show-current'));
        $this->assertSame('', $this->git('status', '--porcelain'));
        $this->assertStringContainsString('setengah jadi', $this->git('show', 'scrum/run-10:app.php'));
        app(DirectWorkspace::class)->assertClean($project); // tidak melempar lagi
        $this->assertNotNull($run->fresh());
    }

    public function test_unknown_leftovers_on_main_move_to_a_rescue_branch_without_losing_work(): void
    {
        $project = $this->project();
        file_put_contents($this->repo.'/app.php', "<?php\necho 'manual';\n");

        $note = app(DirectWorkspace::class)->recoverLeftovers($project);

        $this->assertStringContainsString('scrumspace/sisa-', (string) $note);
        $this->assertSame('main', $this->git('branch', '--show-current'));
        $this->assertSame('', $this->git('status', '--porcelain'));
        $rescue = $this->git('branch', '--list', 'scrumspace/sisa-*', '--format=%(refname:short)');
        $this->assertStringContainsString('manual', $this->git('show', $rescue.':app.php'));
    }

    public function test_leftovers_of_a_running_run_are_never_touched(): void
    {
        $project = $this->project();
        $this->git('checkout', '-q', '-b', 'scrum/run-11');
        $this->makeRun($project, 'EXECUTING', 'scrum/run-11');
        file_put_contents($this->repo.'/app.php', "<?php\necho 'sedang dikerjakan';\n");

        $this->expectException(RuntimeException::class);
        try {
            app(DirectWorkspace::class)->recoverLeftovers($project);
        } finally {
            $this->assertNotSame('', $this->git('status', '--porcelain'));
        }
    }

    public function test_failed_run_releases_baseline_back_to_main(): void
    {
        $project = $this->project();
        $this->git('checkout', '-q', '-b', 'scrum/run-12');
        $run = $this->makeRun($project, 'FAILED', 'scrum/run-12');
        file_put_contents($this->repo.'/new.php', "<?php\n");

        app(DirectWorkspace::class)->releaseAfterFailure($project, $run);

        $this->assertSame('main', $this->git('branch', '--show-current'));
        $this->assertSame('', $this->git('status', '--porcelain'));
        $this->assertFileDoesNotExist($this->repo.'/new.php');
        $this->assertSame("<?php\n", $this->git('show', 'scrum/run-12:new.php')."\n");
    }

    // ---------- 2. Buka kunci sprint ----------

    public function test_active_sprint_can_be_reopened_and_needs_new_approval(): void
    {
        $user = User::factory()->create();
        $sprint = $this->sprint($this->project(), 'ACTIVE');

        $this->actingAs($user)->get(route('projects.show', $sprint->project_id))->assertOk()->assertSee('Buka kunci sprint');
        $this->actingAs($user)->post(route('sprints.reopen', $sprint))->assertRedirect()->assertSessionHas('status');

        $sprint->refresh();
        $this->assertSame('PLANNING', $sprint->status);
        $this->assertNull($sprint->scope_approved_revision);
        $this->actingAs($user)->post(route('sprints.reopen', $sprint))->assertSessionHasErrors('scope');
    }

    // ---------- 4. Estimasi ulang ----------

    public function test_po_can_request_reestimate_and_old_result_is_kept_as_history(): void
    {
        Bus::fake([AdvanceStoryEstimationJob::class]);
        $user = User::factory()->create();
        $sprint = $this->sprint($this->project());
        $story = Story::create(['project_id' => $sprint->project_id, 'sprint_id' => $sprint->id, 'title' => 'Pagu', 'estimate_points' => 5])->fresh();
        $old = StoryEstimationRun::query()->create(['story_id' => $story->id, 'requested_by' => $user->id, 'story_revision' => $story->revision, 'status' => 'COMPLETED', 'consensus_points' => 5]);

        $this->actingAs($user)->post(route('stories.estimations.reestimate', $story))->assertRedirect()->assertSessionHas('status');

        $this->assertSame('SUPERSEDED', $old->fresh()->status);
        $this->assertSame(1, StoryEstimationRun::query()->where('story_id', $story->id)->where('status', 'QUEUED')->count());
        $this->assertSame(5, $story->fresh()->estimate_points); // poin lama tetap berlaku
        Bus::assertDispatched(AdvanceStoryEstimationJob::class);
    }

    public function test_reestimate_is_refused_while_sprint_is_locked(): void
    {
        $user = User::factory()->create();
        $sprint = $this->sprint($this->project(), 'ACTIVE');
        $story = Story::create(['project_id' => $sprint->project_id, 'sprint_id' => $sprint->id, 'title' => 'Pagu', 'estimate_points' => 5]);

        $this->actingAs($user)->post(route('stories.estimations.reestimate', $story))->assertSessionHasErrors('estimation');
    }
}
