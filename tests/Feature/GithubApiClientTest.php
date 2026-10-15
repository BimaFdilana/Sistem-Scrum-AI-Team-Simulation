<?php

namespace Tests\Feature;

use App\Services\GithubApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GithubApiClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.github.token' => 'ghp_testtoken123']);
    }

    protected function client(): GithubApiClient
    {
        return new GithubApiClient();
    }

    public function test_me_returns_user_on_success(): void
    {
        Http::fake([
            'api.github.com/user' => Http::response([
                'login' => 'bimapo',
                'name' => 'Bima',
                'avatar_url' => 'https://example.com/a.png',
            ]),
        ]);

        $me = $this->client()->me();

        $this->assertSame('bimapo', $me['login']);
        $this->assertSame('Bima', $me['name']);
    }

    public function test_me_returns_null_on_invalid_token(): void
    {
        Http::fake([
            'api.github.com/user' => Http::response(['message' => 'Bad credentials'], 401),
        ]);

        $this->assertNull($this->client()->me());
    }

    public function test_me_returns_null_without_token(): void
    {
        config(['services.github.token' => null]);

        $this->assertNull((new GithubApiClient())->me());
        $this->assertFalse((new GithubApiClient())->hasToken());
    }

    public function test_repo_exists_true_and_false(): void
    {
        Http::fake([
            'api.github.com/repos/bimapo/ada' => Http::response(['full_name' => 'bimapo/ada']),
            'api.github.com/repos/bimapo/tidak-ada' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $this->assertTrue($this->client()->repoExists('bimapo', 'ada'));
        $this->assertFalse($this->client()->repoExists('bimapo', 'tidak-ada'));
    }

    public function test_create_repo_success(): void
    {
        Http::fake([
            'api.github.com/user/repos' => Http::response([
                'name' => 'proyek-baru',
                'full_name' => 'bimapo/proyek-baru',
                'html_url' => 'https://github.com/bimapo/proyek-baru',
                'private' => true,
            ], 201),
        ]);

        $repo = $this->client()->createRepo('proyek-baru');

        $this->assertSame('bimapo/proyek-baru', $repo['full_name']);
        $this->assertTrue($repo['private']);
    }

    public function test_create_repo_failure_returns_null(): void
    {
        Http::fake([
            'api.github.com/user/repos' => Http::response(['message' => 'Validation Failed'], 422),
        ]);

        $this->assertNull($this->client()->createRepo('duplikat'));
    }

    public function test_fork_repo_success(): void
    {
        Http::fake([
            'api.github.com/repos/orang/template/forks' => Http::response([
                'name' => 'template',
                'full_name' => 'bimapo/template',
                'html_url' => 'https://github.com/bimapo/template',
            ], 202),
        ]);

        $fork = $this->client()->forkRepo('orang', 'template');

        $this->assertSame('bimapo/template', $fork['full_name']);
    }

    public function test_create_branch_success(): void
    {
        Http::fake([
            'api.github.com/repos/bimapo/proyek/git/refs' => Http::response([
                'ref' => 'refs/heads/story-12-login',
                'object' => ['sha' => 'abc123'],
            ], 201),
        ]);

        $this->assertTrue($this->client()->createBranch('bimapo', 'proyek', 'story-12-login', 'abc123'));
    }

    public function test_create_branch_failure(): void
    {
        Http::fake([
            'api.github.com/repos/bimapo/proyek/git/refs' => Http::response(['message' => 'Reference already exists'], 422),
        ]);

        $this->assertFalse($this->client()->createBranch('bimapo', 'proyek', 'main', 'abc123'));
    }

    public function test_merge_branch_success(): void
    {
        Http::fake([
            'api.github.com/repos/bimapo/proyek/merges' => Http::response([
                'sha' => 'def456',
                'merged' => true,
            ], 201),
        ]);

        $result = $this->client()->mergeBranch('bimapo', 'proyek', 'integration', 'story-12-login');

        $this->assertTrue($result['merged']);
        $this->assertSame('def456', $result['sha']);
    }

    public function test_merge_branch_conflict_returns_null(): void
    {
        Http::fake([
            'api.github.com/repos/bimapo/proyek/merges' => Http::response(['message' => 'Merge conflict'], 409),
        ]);

        $this->assertNull($this->client()->mergeBranch('bimapo', 'proyek', 'integration', 'story-12-login'));
    }

    public function test_network_failure_does_not_throw(): void
    {
        Http::fake(function () {
            throw new \RuntimeException('Connection refused');
        });

        // Tidak boleh lempar exception — return null/false saja.
        $this->assertNull($this->client()->me());
        $this->assertFalse($this->client()->repoExists('a', 'b'));
        $this->assertNull($this->client()->createRepo('x'));
    }

    public function test_token_from_database_takes_precedence_over_env(): void
    {
        \App\Models\Setting::put('github_pat', 'ghp_dari_database');

        Http::fake([
            'api.github.com/user' => Http::response(['login' => 'dari-db']),
        ]);

        $client = new GithubApiClient();
        $this->assertTrue($client->hasToken());
        $this->assertSame('dari-db', $client->me()['login']);

        // Verifikasi token yang dipakai adalah dari DB (via header Authorization).
        Http::assertSent(function ($request) {
            return $request->header('Authorization')[0] === 'Bearer ghp_dari_database';
        });
    }
}
