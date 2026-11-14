<?php

namespace Tests\Feature;

use App\Models\ExecutionRun;
use App\Models\Project;
use App\Models\ProjectRelease;
use App\Models\Story;
use App\Models\User;
use App\Support\Release\ReleaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Demo & Rilis: satukan dengan GitHub lalu kirim, diuji dengan repository bare lokal sebagai "GitHub". */
class ReleaseToGithubTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private string $work;

    private string $remote;

    private string $other;

    private function git(string $cwd, string ...$args): string
    {
        $process = new Process(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', ...$args], $cwd);
        $process->mustRun();

        return trim($process->getOutput());
    }

    private function project(): Project
    {
        $this->root = storage_path('framework/testing/rel-'.uniqid());
        $this->work = $this->root.'/work';
        $this->remote = $this->root.'/remote.git';
        $this->other = $this->root.'/other';
        mkdir($this->work, 0700, true);
        $this->git($this->work, 'init', '-q', '-b', 'main');
        file_put_contents($this->work.'/rab.php', "baris1\nbaris2\n");
        file_put_contents($this->work.'/lain.php', "lain\n");
        $this->git($this->work, 'add', '.');
        $this->git($this->work, 'commit', '-qm', 'awal');
        $this->git($this->root, 'clone', '-q', '--bare', $this->work, $this->remote);
        $this->git($this->work, 'remote', 'add', 'origin', $this->remote);
        $this->git($this->work, 'fetch', '-q', 'origin');
        $this->git($this->root, 'clone', '-q', $this->remote, $this->other);

        return Project::query()->create(['name' => 'SIPUA', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'BASELINE_READY', 'workspace_path' => $this->work]);
    }

    /** Hasil kerja tim di ScrumSpace: commit task lalu "Merge scrum/run-N". */
    private function teamWork(Project $project, string $file, string $content): void
    {
        $story = Story::create(['project_id' => $project->id, 'title' => 'Pagu unit', 'estimate_points' => 3]);
        $run = ExecutionRun::query()->create(['story_id' => $story->id, 'requested_by' => User::factory()->create()->id, 'status' => 'COMPLETED', 'mode' => 'direct']);
        $this->git($this->work, 'checkout', '-q', '-b', 'scrum/run-'.$run->id);
        file_put_contents($this->work.'/'.$file, $content);
        $this->git($this->work, 'commit', '-qam', 'Task #1');
        $this->git($this->work, 'checkout', '-q', 'main');
        $this->git($this->work, 'merge', '-q', '--no-ff', '-m', 'Merge scrum/run-'.$run->id, 'scrum/run-'.$run->id);
    }

    /** Perubahan langsung di GitHub (di luar ScrumSpace). */
    private function githubChange(string $file, string $content): void
    {
        file_put_contents($this->other.'/'.$file, $content);
        $this->git($this->other, 'commit', '-qam', 'ubah di GitHub');
        $this->git($this->other, 'push', '-q', 'origin', 'main');
    }

    public function test_status_unify_and_push_with_backup_tag(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $project = $this->project();
        $this->teamWork($project, 'rab.php', "baris1\nbaris2 dari tim\n");
        $this->githubChange('lain.php', "lain dari github\n");

        $status = app(ReleaseManager::class)->status($project);
        $this->assertSame(2, $status['ahead']);
        $this->assertSame(1, $status['behind']);
        $this->assertSame(['US-'.str_pad((string) Story::first()->id, 2, '0', STR_PAD_LEFT).' — Pagu unit'], $status['items']);

        $this->actingAs($user)->get(route('projects.release.show', $project))->assertOk()
            ->assertSee('Satukan menjadi 1 project')->assertSee('1 perubahan');

        // Kirim ditolak sebelum disatukan.
        $this->actingAs($user)->post(route('projects.release.push', $project))->assertSessionHasErrors('release');

        $this->actingAs($user)->post(route('projects.release.unify', $project))->assertSessionHasNoErrors();
        $this->assertSame("lain dari github\n", $this->git($this->work, 'show', 'main:lain.php')."\n");
        $this->assertSame("baris1\nbaris2 dari tim", $this->git($this->work, 'show', 'main:rab.php'));

        $this->actingAs($user)->post(route('projects.release.push', $project))->assertSessionHasNoErrors();
        $this->assertSame($this->git($this->work, 'rev-parse', 'main'), $this->git($this->remote, 'rev-parse', 'main'));
        $release = ProjectRelease::query()->sole();
        $this->assertSame('PUSHED', $release->status);
        $this->assertStringStartsWith('scrumspace/sebelum-rilis-', $release->backup_tag);
        $this->assertSame($release->previous_sha, $this->git($this->remote, 'rev-parse', $release->backup_tag));
    }

    public function test_conflict_changes_nothing_then_po_picks_scrumspace_version(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $project = $this->project();
        $this->teamWork($project, 'rab.php', "baris1\nversi tim\n");
        $this->githubChange('rab.php', "baris1\nversi github\n");
        $before = $this->git($this->work, 'rev-parse', 'main');

        $this->actingAs($user)->post(route('projects.release.unify', $project))
            ->assertSessionHasErrors('release')->assertSessionHas('release_conflicts', ['rab.php']);
        $this->assertSame($before, $this->git($this->work, 'rev-parse', 'main')); // tidak ada yang berubah
        $this->assertSame('', $this->git($this->work, 'status', '--porcelain'));

        $this->actingAs($user)->post(route('projects.release.unify', $project), ['strategy' => 'ours'])->assertSessionHasNoErrors();
        $this->assertSame("baris1\nversi tim", $this->git($this->work, 'show', 'main:rab.php'));
        $this->assertSame(0, app(ReleaseManager::class)->status($project)['behind']);
    }

    public function test_release_is_blocked_while_team_is_working(): void
    {
        $user = User::factory()->create();
        $project = $this->project();
        $this->teamWork($project, 'rab.php', "x\n");
        ExecutionRun::query()->create(['story_id' => Story::first()->id, 'requested_by' => $user->id, 'status' => 'EXECUTING', 'mode' => 'direct']);

        $this->actingAs($user)->post(route('projects.release.push', $project))->assertSessionHasErrors('release');
        $this->assertSame(0, ProjectRelease::query()->count());
    }
}
