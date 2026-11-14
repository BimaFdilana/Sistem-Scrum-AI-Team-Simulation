<?php

namespace Tests\Feature;

use App\Jobs\RefreshDemoJob;
use App\Models\ExecutionRun;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use App\Support\Demo\DemoContents;
use App\Support\Demo\DemoServerManager;
use App\Support\Execution\DirectWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DemoContentsTest extends TestCase
{
    use RefreshDatabase;

    private string $repo;

    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            parent::tearDown();
        }
    }

    private function git(string ...$args): string
    {
        $process = new Process(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', ...$args], $this->repo);
        $process->mustRun();

        return trim($process->getOutput());
    }

    private function project(): Project
    {
        $this->repo = storage_path('framework/testing/dcn-'.uniqid());
        mkdir($this->repo, 0700, true);
        $this->git('init', '-q', '-b', 'main');
        file_put_contents($this->repo.'/app.php', "baris satu\n");
        $this->git('add', '.');
        $this->git('commit', '-q', '-m', 'awal');

        return Project::query()->create(['name' => 'DC', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'BASELINE_READY', 'workspace_path' => $this->repo]);
    }

    /** Story dengan run selesai pada cabang kerjanya (dibuat dari main, satu komit). */
    private function storyWithBranch(Project $project, int $n, ?string $acceptance, ?string $file = null): Story
    {
        $story = Story::query()->create(['project_id' => $project->id, 'title' => 'Story '.$n, 'estimate_points' => 3, 'user_story_key' => 'US-0'.$n, 'revision' => 1, 'acceptance_status' => $acceptance]);
        $run = ExecutionRun::query()->create(['story_id' => $story->id, 'requested_by' => User::factory()->create()->id, 'status' => 'COMPLETED', 'mode' => 'direct']);
        $branch = 'scrum/run-'.$run->id;
        $this->git('checkout', '-q', '-b', $branch, 'main');
        file_put_contents($this->repo.'/'.($file ?? "fitur{$n}.php"), "isi {$n}\n");
        $this->git('add', '.');
        $this->git('commit', '-q', '-m', 'story '.$n);
        $this->git('checkout', '-q', 'main');
        $run->forceFill(['work_branch' => $branch, 'base_branch' => 'main'])->save();

        return $story;
    }

    public function test_accepted_story_whose_branch_is_merged_is_included_and_an_unmerged_one_is_flagged(): void
    {
        $project = $this->project();
        $this->storyWithBranch($project, 1, 'BERHASIL');
        $this->git('merge', '-q', '--no-ff', '-m', 'merge', 'scrum/run-1');
        $this->storyWithBranch($project, 2, 'BERHASIL'); // diterima tetapi tidak tergabung

        $contents = DemoContents::for($project);

        $this->assertSame('main', $contents['branch']);
        $this->assertSame([true, false], array_column($contents['accepted'], 'included'));
        $this->assertSame([], $contents['pending']);
    }

    public function test_completed_but_unaccepted_story_is_pending_and_only_shown_when_its_branch_is_active(): void
    {
        $project = $this->project();
        $this->storyWithBranch($project, 1, null);

        $this->assertFalse(DemoContents::for($project)['pending'][0]['shown'], 'main tidak memuat cabang run');

        $this->git('checkout', '-q', 'scrum/run-1');
        $this->assertTrue(DemoContents::for($project)['pending'][0]['shown'], 'cabang run aktif tampil sementara');
    }

    public function test_story_from_the_direct_commit_era_counts_as_included(): void
    {
        $project = $this->project();
        $story = Story::query()->create(['project_id' => $project->id, 'title' => 'Lama', 'estimate_points' => 3, 'user_story_key' => 'US-09', 'revision' => 1, 'acceptance_status' => 'BERHASIL']);
        ExecutionRun::query()->create(['story_id' => $story->id, 'requested_by' => User::factory()->create()->id, 'status' => 'COMPLETED', 'mode' => 'direct']);

        $this->assertTrue(DemoContents::for($project)['accepted'][0]['included']);
    }

    public function test_project_without_a_workspace_yields_an_empty_list(): void
    {
        $project = Project::query()->create(['name' => 'X', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'SCAFFOLDING']);

        $this->assertSame(['branch' => null, 'accepted' => [], 'pending' => []], DemoContents::for($project));
    }

    public function test_project_page_lists_what_the_demo_contains(): void
    {
        $project = $this->project();
        $this->storyWithBranch($project, 1, 'BERHASIL');
        $this->git('merge', '-q', '--no-ff', '-m', 'merge', 'scrum/run-1');
        $this->storyWithBranch($project, 2, 'BERHASIL');
        $this->mock(DemoServerManager::class, fn ($mock) => $mock->shouldReceive('runningFor')->andReturn(null));

        $this->actingAs(User::factory()->create())->get(route('projects.release.show', $project))
            ->assertOk()->assertSee('Isi demo')->assertSee('US-01 Story 1')->assertSee('ada di demo')
            ->assertSee('belum ada di demo')->assertSee('scrum/run-2');
    }

    public function test_accepting_a_story_refreshes_the_running_demo_in_the_background(): void
    {
        Queue::fake();
        $project = $this->project();
        $story = $this->storyWithBranch($project, 1, null);
        $run = $story->executionRuns()->first();
        $this->mock(DemoServerManager::class, function ($mock): void {
            $mock->shouldReceive('verify')->andReturn(['ok' => true, 'http' => 200, 'url' => 'http://demo.test', 'error' => null, 'demo' => null]);
            $mock->shouldReceive('runningFor')->andReturn(null);
        });

        $this->actingAs(User::factory()->create())->post(route('stories.executions.accept', [$story, $run]), ['status' => 'BERHASIL']);

        $this->assertSame('BERHASIL', $story->fresh()->acceptance_status);
        Queue::assertPushed(RefreshDemoJob::class, fn ($job) => $job->projectId === $project->id);
    }

    public function test_rejecting_a_story_refreshes_the_demo_back_to_main_code(): void
    {
        Queue::fake();
        $project = $this->project();
        $story = $this->storyWithBranch($project, 1, null);
        $this->mock(DemoServerManager::class, fn ($mock) => $mock->shouldReceive('runningFor')->andReturn(null));

        $this->actingAs(User::factory()->create())->post(route('stories.executions.accept', [$story, $story->executionRuns()->first()]), ['status' => 'GAGAL']);

        // Hasil yang ditolak tidak boleh tetap tampil di demo: demo dikembalikan ke kode utama.
        Queue::assertPushed(RefreshDemoJob::class);
    }

    public function test_refresh_job_never_breaks_when_the_refresh_itself_fails(): void
    {
        $project = $this->project();
        $this->mock(DemoServerManager::class, fn ($mock) => $mock->shouldReceive('refresh')->andThrow(new \RuntimeException('npm gagal')));

        (new RefreshDemoJob($project->id))->handle(app(DemoServerManager::class));

        $this->assertTrue(true, 'tidak melempar');
    }

    public function test_refresh_without_a_running_demo_does_nothing(): void
    {
        $project = $this->project();

        app(DemoServerManager::class)->refresh($project);

        $this->assertTrue(true, 'tidak melempar');
    }

    public function test_conflicting_acceptance_explains_which_file_clashes(): void
    {
        $project = $this->project();
        $story = $this->storyWithBranch($project, 1, null, 'app.php');
        // main mengubah file yang sama setelah run bercabang -> bentrok
        file_put_contents($this->repo.'/app.php', "baris satu berubah di main\n");
        $this->git('commit', '-qam', 'perubahan lain di main');
        $run = $story->executionRuns()->first();

        $message = app(DirectWorkspace::class)->settle($project, $run, true);

        $this->assertStringContainsString('bentrok', (string) $message);
        $this->assertStringContainsString('app.php', (string) $message);
        $this->assertStringContainsString($run->work_branch, (string) $message);
    }
}
