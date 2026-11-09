<?php

namespace Tests\Feature;

use App\Models\ExecutionRun;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use App\Support\Execution\DirectWorkspace;
use App\Support\Workspace\WorktreeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RunBranchTest extends TestCase
{
    use RefreshDatabase;

    private string $repo;

    private function git(string ...$args): string
    {
        $process = new Process(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', ...$args], $this->repo);
        $process->mustRun();

        return trim($process->getOutput());
    }

    private function makeProject(): Project
    {
        $this->repo = storage_path('framework/testing/rb-'.uniqid());
        mkdir($this->repo, 0700, true);
        $this->git('init', '-q', '-b', 'main');
        file_put_contents($this->repo.'/README.md', 'awal');
        $this->git('add', '.');
        $this->git('commit', '-q', '-m', 'awal');

        return Project::query()->create([
            'name' => 'RB', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'BASELINE_READY', 'workspace_path' => $this->repo,
        ]);
    }

    private function makeRun(Project $project): ExecutionRun
    {
        $story = Story::query()->create(['project_id' => $project->id, 'title' => 'S', 'estimate_points' => 3]);

        return ExecutionRun::query()->create(['story_id' => $story->id, 'requested_by' => User::factory()->create()->id, 'status' => 'EXECUTING', 'mode' => 'direct']);
    }

    private function commitFile(string $name): void
    {
        file_put_contents($this->repo.'/'.$name, 'isi');
        $this->git('add', '.');
        $this->git('commit', '-q', '-m', 'tambah '.$name);
    }

    public function test_run_works_on_its_own_branch_and_snapshots_the_start(): void
    {
        $project = $this->makeProject();
        $run = $this->makeRun($project);
        $startHead = $this->git('rev-parse', 'HEAD');

        app(DirectWorkspace::class)->beginRun($project, $run);
        $this->commitFile('fitur.php');

        $this->assertSame('scrum/run-'.$run->id, $this->git('rev-parse', '--abbrev-ref', 'HEAD'));
        $this->assertSame('main', $run->fresh()->base_branch);
        $this->assertSame($startHead, $this->git('rev-parse', 'refs/scrumspace/run-'.$run->id.'-start'));
        $this->assertSame($startHead, $this->git('rev-parse', 'main'), 'branch utama belum tersentuh');
    }

    public function test_begin_run_is_idempotent_across_tasks(): void
    {
        $project = $this->makeProject();
        $run = $this->makeRun($project);
        $workspace = app(DirectWorkspace::class);

        $workspace->beginRun($project, $run);
        $this->commitFile('satu.php');
        $workspace->beginRun($project, $run->fresh());

        $this->assertSame('scrum/run-'.$run->id, $this->git('rev-parse', '--abbrev-ref', 'HEAD'));
        $this->assertFileExists($this->repo.'/satu.php');
    }

    public function test_accepted_run_is_merged_into_base_branch(): void
    {
        $project = $this->makeProject();
        $run = $this->makeRun($project);
        $workspace = app(DirectWorkspace::class);
        $workspace->beginRun($project, $run);
        $this->commitFile('fitur.php');

        $this->assertNull($workspace->settle($project, $run->fresh(), true));

        $this->assertSame('main', $this->git('rev-parse', '--abbrev-ref', 'HEAD'));
        $this->assertFileExists($this->repo.'/fitur.php');
    }

    public function test_rejected_run_leaves_base_branch_untouched_but_keeps_the_work(): void
    {
        $project = $this->makeProject();
        $run = $this->makeRun($project);
        $workspace = app(DirectWorkspace::class);
        $workspace->beginRun($project, $run);
        $this->commitFile('fitur.php');

        $this->assertNull($workspace->settle($project, $run->fresh(), false));

        $this->assertSame('main', $this->git('rev-parse', '--abbrev-ref', 'HEAD'));
        $this->assertFileDoesNotExist($this->repo.'/fitur.php');
        $this->assertTrue(app(WorktreeManager::class)->branchExists($this->repo, 'scrum/run-'.$run->id), 'hasil ditolak tetap bisa dilihat di branch run');
    }

    public function test_next_run_starts_from_base_branch_not_from_unaccepted_run(): void
    {
        $project = $this->makeProject();
        $first = $this->makeRun($project);
        $workspace = app(DirectWorkspace::class);
        $workspace->beginRun($project, $first);
        $this->commitFile('belum-diputuskan.php');

        $second = $this->makeRun($project);
        $workspace->beginRun($project, $second);

        $this->assertSame('main', $second->fresh()->base_branch);
        $this->assertFileDoesNotExist($this->repo.'/belum-diputuskan.php');
        $this->assertSame('scrum/run-'.$second->id, $this->git('rev-parse', '--abbrev-ref', 'HEAD'));
    }

    public function test_settle_without_work_branch_does_nothing(): void
    {
        $project = $this->makeProject();
        $run = $this->makeRun($project);

        $this->assertNull(app(DirectWorkspace::class)->settle($project, $run, true));
        $this->assertSame('main', $this->git('rev-parse', '--abbrev-ref', 'HEAD'));
    }
}
