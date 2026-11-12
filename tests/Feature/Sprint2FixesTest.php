<?php

namespace Tests\Feature;

use App\Jobs\AdvanceStoryEstimationJob;
use App\Jobs\BreakdownExecutionJob;
use App\Models\ChatMessage;
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

    // ---------- 3. Tanya Scrum Master: perintah chat ke tim ----------

    public function test_chat_command_starts_team_execution_without_backlog(): void
    {
        Bus::fake([BreakdownExecutionJob::class]);
        $user = User::factory()->create();
        $sprint = $this->sprint($this->project());

        $this->actingAs($user)->postJson(route('chat.commands.store'), ['message' => "Tambahkan kolom Pagu di daftar unit\nUrutkan berdasarkan nama", 'sprint_id' => $sprint->id])
            ->assertStatus(202);

        $story = Story::query()->where('origin', 'CHAT')->firstOrFail();
        $this->assertNull($story->sprint_id); // tidak masuk Sprint Backlog
        $this->assertStringStartsWith('PERINTAH-', $story->display_id);
        $this->assertSame(1, ExecutionRun::query()->where('story_id', $story->id)->where('status', 'QUEUED')->count());
        Bus::assertDispatched(BreakdownExecutionJob::class);
        $this->assertDatabaseHas('chat_messages', ['kind' => 'command', 'role' => 'user']);

        $this->actingAs($user)->get('/chat?sprint='.$sprint->id)->assertOk()
            ->assertSee('Laporan Scrum Master')->assertSee('Tanya Scrum Master')
            ->assertSee('perintah untuk tim')->assertDontSee('Ubah kode langsung');
        $this->actingAs($user)->get(route('projects.show', $sprint->project_id))->assertOk()->assertDontSee('Tambahkan kolom Pagu di daftar unit');
    }

    public function test_chat_command_is_refused_while_team_is_busy_in_the_project(): void
    {
        $user = User::factory()->create();
        $project = $this->project();
        $sprint = $this->sprint($project);
        $this->makeRun($project, 'EXECUTING', 'scrum/run-1');

        $this->actingAs($user)->postJson(route('chat.commands.store'), ['message' => 'Ganti judul halaman', 'sprint_id' => $sprint->id])
            ->assertStatus(409);
    }

    public function test_scrum_master_reports_each_stage_once_with_decision_buttons(): void
    {
        $user = User::factory()->create();
        $sprint = $this->sprint($this->project());
        Bus::fake([BreakdownExecutionJob::class]);
        $this->actingAs($user)->postJson(route('chat.commands.store'), ['message' => 'Ganti judul halaman', 'sprint_id' => $sprint->id])->assertStatus(202);
        $run = ExecutionRun::query()->latest('id')->firstOrFail();
        $run->tasks()->create(['title' => 'Ganti judul di layout', 'assignee' => 'frontend-developer', 'status' => 'PENDING', 'sort_order' => 0]);

        $run->forceFill(['status' => 'EXECUTING'])->save();
        $run->forceFill(['status' => 'EXECUTING', 'summary' => 'x'])->save(); // tidak dobel
        $run->forceFill(['status' => 'COMPLETED', 'summary' => 'Lulus review gabungan.'])->save();

        $reports = ChatMessage::query()->where('kind', 'report')->where('execution_run_id', $run->id)->pluck('content');
        $this->assertCount(2, $reports);
        $this->assertStringContainsString('[FE] Ganti judul di layout', $reports[0]);
        $this->assertStringContainsString('lolos pemeriksaan QA', $reports[1]);

        $this->actingAs($user)->get('/chat?sprint='.$sprint->id)->assertOk()
            ->assertSee('✓ Terima')->assertSee('Tandai Gagal')
            ->assertSee(route('stories.executions.accept', [$run->story_id, $run]), false);
    }

    public function test_failed_chat_command_offers_rerun_without_estimate(): void
    {
        Bus::fake([BreakdownExecutionJob::class]);
        $user = User::factory()->create();
        $sprint = $this->sprint($this->project());
        $this->actingAs($user)->postJson(route('chat.commands.store'), ['message' => 'Ganti judul halaman', 'sprint_id' => $sprint->id])->assertStatus(202);
        $run = ExecutionRun::query()->latest('id')->firstOrFail();
        $run->forceFill(['status' => 'FAILED', 'summary' => 'Task #1 gagal: timeout'])->save();

        $this->actingAs($user)->get('/chat?sprint='.$sprint->id)->assertOk()->assertSee('Jalankan ulang')->assertSee('Task #1 gagal: timeout');
        $this->actingAs($user)->post(route('stories.executions.store', $run->story_id))->assertRedirect();
        $this->assertSame(2, ExecutionRun::query()->where('story_id', $run->story_id)->count());
    }

    public function test_status_of_a_command_is_private(): void
    {
        Bus::fake([BreakdownExecutionJob::class]);
        $owner = User::factory()->create();
        $sprint = $this->sprint($this->project());
        $this->actingAs($owner)->postJson(route('chat.commands.store'), ['message' => 'Ganti judul halaman', 'sprint_id' => $sprint->id]);
        $run = ExecutionRun::query()->latest('id')->firstOrFail();

        $this->actingAs($owner)->getJson(route('chat.commands.status', $run))->assertOk()->assertJsonPath('active', true);
        $this->actingAs(User::factory()->create())->getJson(route('chat.commands.status', $run))->assertNotFound();
    }
}
