<?php

namespace Tests\Feature;

use App\Models\ExecutionRun;
use App\Models\Project;
use App\Models\RepairOrder;
use App\Models\Story;
use App\Models\User;
use App\Services\GithubApiClient;
use App\Services\GithubProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Test Fase 3 integrasi GitHub:
 * - Story branch dibuat saat eksekusi
 * - Merge ke integration saat Accept Berhasil
 * - Merge konflik → pesan jelas, story tidak ditandai Berhasil
 * - Demo pakai branch integration
 * - Graceful tanpa GitHub (flow lokal tetap jalan)
 */
class GithubPhase3Test extends TestCase
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

    protected function projectWithGithub(): Project
    {
        $workspace = sys_get_temp_dir().'/test-workspace-'.uniqid();
        mkdir($workspace, 0777, true);

        return Project::query()->create([
            'name' => 'Toko Online',
            'project_type' => 'new',
            'status' => 'BASELINE_READY',
            'github_owner' => 'bima-po',
            'github_repo' => 'toko-online',
            'workspace_path' => $workspace,
        ]);
    }

    protected function storyFor(Project $project): Story
    {
        return Story::query()->create([
            'project_id' => $project->id,
            'title' => 'Tambah Keranjang Belanja',
            'estimate_points' => 5,
        ]);
    }

    // ------------------------------------------------------------------
    // 1. Story branch
    // ------------------------------------------------------------------

    public function test_create_story_branch_returns_expected_name(): void
    {
        $service = new GithubProjectService($this->mockClient([
            'branchSha' => 'abc123', // untuk branch baru & main
            'createBranch' => true,
        ]));

        $branch = $service->createStoryBranch('bima-po', 'toko-online', 42, 'Tambah Keranjang Belanja!');

        $this->assertSame('story-42-tambah-keranjang-belanja', $branch);
    }

    public function test_create_story_branch_returns_existing_without_api_call(): void
    {
        $client = Mockery::mock(GithubApiClient::class)->makePartial();
        // branchSha dipanggil sekali untuk cek existing → return SHA (ada).
        $client->shouldReceive('branchSha')->once()->andReturn('existing-sha');
        $client->shouldNotReceive('createBranch');

        $service = new GithubProjectService($client);
        $branch = $service->createStoryBranch('bima-po', 'toko-online', 7, 'Login');

        $this->assertSame('story-7-login', $branch);
    }

    public function test_create_story_branch_returns_null_when_main_missing(): void
    {
        $service = new GithubProjectService($this->mockClient([
            'branchSha' => null,
        ]));

        $this->assertNull($service->createStoryBranch('bima-po', 'toko-online', 1, 'X'));
    }

    // ------------------------------------------------------------------
    // 2. Integration branch
    // ------------------------------------------------------------------

    public function test_ensure_integration_branch_skips_when_exists(): void
    {
        $client = Mockery::mock(GithubApiClient::class)->makePartial();
        $client->shouldReceive('branchSha')->with('bima-po', 'toko-online', 'integration')->once()->andReturn('sha-int');
        $client->shouldNotReceive('createBranch');

        (new GithubProjectService($client))->ensureIntegrationBranch('bima-po', 'toko-online');
        $this->assertTrue(true); // Tidak throw = sukses.
    }

    public function test_ensure_integration_branch_creates_from_main(): void
    {
        $client = Mockery::mock(GithubApiClient::class)->makePartial();
        $client->shouldReceive('branchSha')->with('bima-po', 'toko-online', 'integration')->once()->andReturn(null);
        $client->shouldReceive('branchSha')->with('bima-po', 'toko-online', 'main')->once()->andReturn('main-sha');
        $client->shouldReceive('createBranch')->with('bima-po', 'toko-online', 'integration', 'main-sha')->once()->andReturn(true);

        (new GithubProjectService($client))->ensureIntegrationBranch('bima-po', 'toko-online');
        $this->assertTrue(true);
    }

    public function test_ensure_integration_branch_throws_when_create_fails(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Gagal membuat branch integration');

        $client = Mockery::mock(GithubApiClient::class)->makePartial();
        $client->shouldReceive('branchSha')->andReturnUsing(
            fn ($o, $r, $b) => $b === 'integration' ? null : 'main-sha'
        );
        $client->shouldReceive('createBranch')->andReturn(false);

        (new GithubProjectService($client))->ensureIntegrationBranch('bima-po', 'toko-online');
    }

    // ------------------------------------------------------------------
    // 3. Accept → merge ke integration
    // ------------------------------------------------------------------

    public function test_accept_berhasil_merges_story_branch_to_integration(): void
    {
        $project = $this->projectWithGithub();
        $story = $this->storyFor($project);
        $run = ExecutionRun::query()->create([
            'story_id' => $story->id,
            'requested_by' => User::factory()->create()->id,
            'status' => 'COMPLETED',
            'github_branch' => 'story-1-tambah-keranjang-belanja',
        ]);

        $githubMock = $this->mockClient([
            'hasToken' => true,
            'branchSha' => 'some-sha', // integration sudah ada
            'mergeBranch' => ['sha' => 'merge-sha', 'merged' => true],
        ]);
        $serviceMock = Mockery::mock(GithubProjectService::class)->makePartial();
        $serviceMock->shouldReceive('isConnected')->andReturn(true);
        $serviceMock->shouldReceive('ensureIntegrationBranch')->once();
        $serviceMock->shouldReceive('syncBranchFromRemote')->once();

        $this->app->instance(GithubApiClient::class, $githubMock);
        $this->app->instance(GithubProjectService::class, $serviceMock);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('stories.executions.accept', [$story, $run]), ['status' => 'BERHASIL']);

        $response->assertRedirect();
        $this->assertSame('BERHASIL', $story->fresh()->acceptance_status);
    }

    public function test_accept_berhasil_rejected_on_merge_conflict(): void
    {
        $project = $this->projectWithGithub();
        $story = $this->storyFor($project);
        $run = ExecutionRun::query()->create([
            'story_id' => $story->id,
            'requested_by' => User::factory()->create()->id,
            'status' => 'COMPLETED',
            'github_branch' => 'story-1-konflik',
        ]);

        $githubMock = $this->mockClient([
            'hasToken' => true,
            'branchSha' => 'some-sha',
            'mergeBranch' => null, // Konflik!
        ]);
        $serviceMock = Mockery::mock(GithubProjectService::class)->makePartial();
        $serviceMock->shouldReceive('isConnected')->andReturn(true);
        $serviceMock->shouldReceive('ensureIntegrationBranch')->once();

        $this->app->instance(GithubApiClient::class, $githubMock);
        $this->app->instance(GithubProjectService::class, $serviceMock);

        $response = $this->actingAs(User::factory()->create())
            ->from(route('stories.executions.show', [$story, $run]))
            ->post(route('stories.executions.accept', [$story, $run]), ['status' => 'BERHASIL']);

        $response->assertRedirect(route('stories.executions.show', [$story, $run]));
        $response->assertSessionHasErrors(['acceptance']);
        // Story TIDAK ditandai Berhasil.
        $this->assertNull($story->fresh()->acceptance_status);
    }

    public function test_accept_gagal_does_not_merge(): void
    {
        $project = $this->projectWithGithub();
        $story = $this->storyFor($project);
        $run = ExecutionRun::query()->create([
            'story_id' => $story->id,
            'requested_by' => User::factory()->create()->id,
            'status' => 'COMPLETED',
            'github_branch' => 'story-1-x',
        ]);

        $githubMock = Mockery::mock(GithubApiClient::class)->makePartial();
        $githubMock->shouldNotReceive('mergeBranch');
        $this->app->instance(GithubApiClient::class, $githubMock);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('stories.executions.accept', [$story, $run]), ['status' => 'GAGAL']);

        $response->assertRedirect();
        $this->assertSame('GAGAL', $story->fresh()->acceptance_status);
    }

    // ------------------------------------------------------------------
    // 4. Graceful tanpa GitHub
    // ------------------------------------------------------------------

    public function test_execution_without_github_runs_local_flow(): void
    {
        $project = Project::query()->create([
            'name' => 'Lokal Saja',
            'project_type' => 'new',
            'status' => 'BASELINE_READY',
            // Tanpa github_owner/github_repo.
        ]);
        // Workspace tidak ada → store tetap menolak dengan pesan yang jelas
        // (bukan error GitHub). Ini membuktikan flow lokal tidak tergantung GitHub.
        $story = $this->storyFor($project);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('stories.executions.store', $story));

        $response->assertSessionHasErrors(['execution']);
        $this->assertSame(0, ExecutionRun::query()->where('story_id', $story->id)->count());
    }

    public function test_accept_without_github_branch_uses_local_flow(): void
    {
        $project = $this->projectWithGithub();
        $story = $this->storyFor($project);
        $run = ExecutionRun::query()->create([
            'story_id' => $story->id,
            'requested_by' => User::factory()->create()->id,
            'status' => 'COMPLETED',
            'github_branch' => null, // Tidak ada story branch.
        ]);

        $githubMock = Mockery::mock(GithubApiClient::class)->makePartial();
        $githubMock->shouldNotReceive('mergeBranch');
        $this->app->instance(GithubApiClient::class, $githubMock);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('stories.executions.accept', [$story, $run]), ['status' => 'BERHASIL']);

        $response->assertRedirect();
        $this->assertSame('BERHASIL', $story->fresh()->acceptance_status);
    }

    // ------------------------------------------------------------------
    // 5. Demo request fix
    // ------------------------------------------------------------------

    public function test_demo_request_fix_creates_repair_order(): void
    {
        $project = $this->projectWithGithub();
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->post(route('projects.demo.request-fix', $project));

        $response->assertRedirect(route('repair-orders.show', RepairOrder::query()->latest()->first()));

        $order = RepairOrder::query()->latest()->first();
        $this->assertSame('integration', $order->target_branch);
        $this->assertSame('DRAFT', $order->status);
        $this->assertSame($project->id, $order->project_id);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
