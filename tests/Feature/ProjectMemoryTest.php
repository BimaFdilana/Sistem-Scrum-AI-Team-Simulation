<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Story;
use App\Support\Ai\ProjectMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProjectMemoryTest extends TestCase
{
    use RefreshDatabase;

    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = storage_path('framework/testing/memori-'.uniqid());
        File::ensureDirectoryExists($this->repo);
        foreach ([['git', 'init', '-q', '-b', 'main'], ['git', 'commit', '-q', '--allow-empty', '-m', 'Tambah halaman usulan']] as $command) {
            (new Process(array_merge(array_slice($command, 0, 1), ['-c', 'user.email=t@t', '-c', 'user.name=t'], array_slice($command, 1)), $this->repo))->mustRun();
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->repo);
        parent::tearDown();
    }

    private function project(): Project
    {
        $project = Project::query()->create(['name' => 'SIPUA', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'BASELINE_READY', 'workspace_path' => $this->repo]);
        Story::query()->create(['project_id' => $project->id, 'title' => 'Verifikasi usulan', 'acceptance_status' => 'BERHASIL', 'accepted_at' => now()]);
        Story::query()->create(['project_id' => $project->id, 'title' => 'Ekspor PDF', 'acceptance_status' => 'GAGAL']);

        return $project;
    }

    public function test_memory_summarises_decisions_and_git_history(): void
    {
        $memory = app(ProjectMemory::class)->build($this->project());

        $this->assertStringContainsString('# Memori proyek SIPUA', $memory);
        $this->assertStringContainsString('Verifikasi usulan', $memory);
        $this->assertStringContainsString('Ekspor PDF', $memory);
        $this->assertStringContainsString('Tambah halaman usulan', $memory);
        $this->assertLessThanOrEqual(ProjectMemory::MAX_BYTES + 100, strlen($memory));
    }

    public function test_installed_memory_is_a_rule_file_that_git_ignores(): void
    {
        app(ProjectMemory::class)->installInto($this->repo, $this->project());

        $rule = (string) file_get_contents($this->repo.'/'.ProjectMemory::RULE_PATH);
        $this->assertStringStartsWith("---\ntrigger: always_on\n", $rule);

        $status = new Process(['git', 'status', '--porcelain'], $this->repo);
        $status->mustRun();
        $this->assertSame('', trim($status->getOutput()));
    }
}
