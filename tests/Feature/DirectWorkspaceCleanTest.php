<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Support\Execution\DirectWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DirectWorkspaceCleanTest extends TestCase
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
        $this->repo = storage_path('framework/testing/dc-'.uniqid());
        mkdir($this->repo, 0700, true);
        $this->git('init', '-q', '-b', 'main');
        file_put_contents($this->repo.'/package-lock.json', "{\n  \"name\": \"sipua\"\n}\n");
        file_put_contents($this->repo.'/app.php', 'awal');
        $this->git('add', '.');
        $this->git('commit', '-q', '-m', 'awal');

        return Project::query()->create([
            'name' => 'DC', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'BASELINE_READY', 'workspace_path' => $this->repo,
        ]);
    }

    public function test_lockfile_only_noise_is_restored_and_run_may_start(): void
    {
        $project = $this->makeProject();
        file_put_contents($this->repo.'/package-lock.json', "{\n  \"name\": \"baseline\"\n}\n");

        app(DirectWorkspace::class)->assertClean($project);

        $this->assertSame('', $this->git('status', '--porcelain'));
        $this->assertStringContainsString('"sipua"', (string) file_get_contents($this->repo.'/package-lock.json'));
    }

    public function test_real_changes_are_still_refused_and_left_untouched(): void
    {
        $project = $this->makeProject();
        file_put_contents($this->repo.'/package-lock.json', "{\n  \"name\": \"baseline\"\n}\n");
        file_put_contents($this->repo.'/app.php', 'pekerjaan PO yang belum di-commit');

        try {
            app(DirectWorkspace::class)->assertClean($project);
            $this->fail('Perubahan nyata harus ditolak.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('tidak bersih', $exception->getMessage());
        }

        $this->assertSame('pekerjaan PO yang belum di-commit', file_get_contents($this->repo.'/app.php'));
        $this->assertStringContainsString('"baseline"', (string) file_get_contents($this->repo.'/package-lock.json'), 'lockfile tidak disentuh bila ada perubahan lain');
    }

    public function test_untracked_new_file_is_never_deleted(): void
    {
        $project = $this->makeProject();
        file_put_contents($this->repo.'/catatan.txt', 'file baru milik pengguna');

        try {
            app(DirectWorkspace::class)->assertClean($project);
            $this->fail('File baru yang belum dilacak harus ditolak.');
        } catch (\RuntimeException) {
            // diharapkan
        }

        $this->assertFileExists($this->repo.'/catatan.txt');
    }
}
