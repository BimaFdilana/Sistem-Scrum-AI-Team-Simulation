<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Services\GithubApiClient;
use App\Services\GithubProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class GithubProjectServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function mockClient(array $stubs): GithubApiClient
    {
        $client = Mockery::mock(GithubApiClient::class)->makePartial();
        foreach ($stubs as $method => $return) {
            $client->shouldReceive($method)->andReturn($return);
        }

        return $client;
    }

    protected function service(array $stubs): GithubProjectService
    {
        return new GithubProjectService($this->mockClient($stubs));
    }

    protected function project(): Project
    {
        return Project::query()->create([
            'name' => 'Test Project',
            'project_type' => 'new',
            'status' => 'BASELINE_READY',
        ]);
    }

    public function test_ensure_repo_for_new_project_success(): void
    {
        $service = $this->service([
            'me' => ['login' => 'bima-po'],
            'repoExists' => false,
            'createRepo' => ['name' => 'toko-baru', 'full_name' => 'bima-po/toko-baru', 'html_url' => '', 'private' => true],
        ]);

        $result = $service->ensureRepoForNewProject($this->project(), 'toko-baru');

        $this->assertSame('bima-po', $result['owner']);
        $this->assertSame('toko-baru', $result['repo']);
    }

    public function test_ensure_repo_for_new_project_rejects_existing_repo(): void
    {
        $service = $this->service([
            'me' => ['login' => 'bima-po'],
            'repoExists' => true,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/sudah ada.*pakai nama lain/i');

        $service->ensureRepoForNewProject($this->project(), 'toko-lama');
    }

    public function test_ensure_repo_for_new_project_rejects_invalid_name(): void
    {
        $service = $this->service(['me' => ['login' => 'bima-po']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/tidak valid/i');

        $service->ensureRepoForNewProject($this->project(), 'nama repo!@#');
    }

    public function test_ensure_repo_for_new_project_requires_connection(): void
    {
        $service = $this->service(['me' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/tidak terhubung/i');

        $service->ensureRepoForNewProject($this->project(), 'toko-baru');
    }

    public function test_ensure_repo_for_import_uses_existing_repo(): void
    {
        $service = $this->service([
            'me' => ['login' => 'bima-po'],
            'repoExists' => true,
        ]);

        $result = $service->ensureRepoForImport(
            $this->project(),
            'https://github.com/bima-po/toko-saya'
        );

        $this->assertSame('bima-po', $result['owner']);
        $this->assertSame('toko-saya', $result['repo']);
        $this->assertSame('existing', $result['action']);
    }

    public function test_ensure_repo_for_import_forks_when_missing(): void
    {
        $service = $this->service([
            'me' => ['login' => 'bima-po'],
            'repoExists' => false,
            'forkRepo' => ['name' => 'template', 'full_name' => 'bima-po/template', 'html_url' => ''],
        ]);

        $result = $service->ensureRepoForImport(
            $this->project(),
            'https://github.com/orang/template'
        );

        $this->assertSame('bima-po', $result['owner']);
        $this->assertSame('template', $result['repo']);
        $this->assertSame('forked', $result['action']);
    }

    public function test_ensure_repo_for_import_fork_failure_gives_clear_message(): void
    {
        $service = $this->service([
            'me' => ['login' => 'bima-po'],
            'repoExists' => false,
            'forkRepo' => null,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/gagal fork.*private|tidak punya akses/i');

        $service->ensureRepoForImport(
            $this->project(),
            'https://github.com/orang/private-repo'
        );
    }

    public function test_ensure_repo_for_import_rejects_invalid_url(): void
    {
        $service = $this->service(['me' => ['login' => 'bima-po']]);

        $this->expectException(RuntimeException::class);

        $service->ensureRepoForImport($this->project(), 'https://gitlab.com/orang/repo');
    }

    public function test_push_baseline_requires_token(): void
    {
        $service = $this->service([
            'token' => null,
            'hasToken' => false,
        ]);

        $project = $this->project();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/tidak terhubung/i');

        $service->pushBaseline($project, 'bima-po', 'toko-baru');
    }

    public function test_push_baseline_requires_workspace(): void
    {
        $service = $this->service([
            'token' => 'fake-token',
            'hasToken' => true,
        ]);

        $project = $this->project();
        $project->forceFill(['workspace_path' => '/tidak/ada'])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/workspace.*belum siap/i');

        $service->pushBaseline($project, 'bima-po', 'toko-baru');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
