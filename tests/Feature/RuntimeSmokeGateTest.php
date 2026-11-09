<?php

namespace Tests\Feature;

use App\Jobs\BatchReviewExecutionJob;
use App\Jobs\ExecuteExecutionTaskJob;
use App\Models\ExecutionFinding;
use App\Models\ExecutionRun;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use App\Services\AgentProfileClient;
use App\Support\Demo\DemoServerManager;
use App\Support\Execution\BatchQa;
use App\Support\Execution\ProjectFacts;
use App\Support\Execution\RuntimeSmoke;
use App\Support\Workspace\WorktreeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PDO;
use Tests\TestCase;

class RuntimeSmokeGateTest extends TestCase
{
    use RefreshDatabase;

    private const CRASH = [
        'kind' => 'crash', 'method' => 'GET', 'uri' => '/iku', 'status' => 500, 'as_role' => 'admin',
        'route_file' => 'app/Http/Controllers/IkuController.php',
        'error' => 'Illuminate\\Database\\QueryException: table ikus has no column named kode',
        'file' => 'app/Http/Controllers/IkuController.php', 'line' => 42,
    ];

    private const APPROVED = '{"approved":true,"feedback":"ok","criteria":[{"criterion":"IKU bisa ditambah","met":true,"evidence":"IkuController@store"}]}';

    /**
     * @param  array<int, array<string, mixed>>  $results  hasil kode run ini
     * @param  array<int, array<string, mixed>>|null  $baseResults  hasil kode sebelum run (null = tidak diuji)
     */
    private function fakeSmoke(array $results, bool $ran = true, ?array $baseResults = null, ?array $branchAtRef = null): void
    {
        $smoke = Mockery::mock(RuntimeSmoke::class, [app(DemoServerManager::class)])->makePartial();
        $smoke->shouldReceive('run')->andReturn(['ran' => $ran, 'error' => $ran ? null : 'tidak siap', 'roles' => ['admin'], 'results' => $results]);
        $smoke->shouldReceive('runAtRef')->andReturnUsing(function ($project, string $ref) use ($baseResults, $branchAtRef) {
            $results = str_starts_with($ref, 'refs/scrumspace/') ? $baseResults : $branchAtRef;

            return $results === null
                ? ['ran' => false, 'error' => 'tidak diuji', 'roles' => [], 'results' => []]
                : ['ran' => true, 'error' => null, 'roles' => ['admin'], 'results' => $results];
        });
        $this->app->instance(RuntimeSmoke::class, $smoke);
    }

    private function directRunGit(ExecutionRun $run): void
    {
        $git = Mockery::mock(WorktreeManager::class);
        $git->shouldReceive('branchExists')->andReturn(true);
        $git->shouldReceive('currentBranch')->andReturn('scrum/run-'.$run->id);
        $git->shouldReceive('changedFilesBetween')->andReturn(['app/Http/Controllers/ProfileController.php']);
        $this->app->instance(WorktreeManager::class, $git);
    }

    private function makeRun(): ExecutionRun
    {
        $dir = storage_path('framework/testing/smoke-'.uniqid());
        mkdir($dir, 0700, true);
        $project = Project::query()->create([
            'name' => 'Smoke', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel',
            'status' => 'BASELINE_READY', 'workspace_path' => $dir,
        ]);
        $story = Story::query()->create([
            'project_id' => $project->id, 'title' => 'IKU', 'estimate_points' => 5,
            'acceptance_criteria' => 'IKU bisa ditambah.',
        ]);
        $run = ExecutionRun::query()->create([
            'story_id' => $story->id, 'requested_by' => User::factory()->create()->id,
            'status' => 'QA_REVIEW', 'mode' => 'direct', 'qa_rounds' => 0,
        ]);
        $run->tasks()->create([
            'title' => 'Controller IKU', 'description' => 'D', 'assignee' => 'backend-developer', 'status' => 'QA_PENDING',
        ]);

        return $run;
    }

    private function review(ExecutionRun $run, AgentProfileClient $agents): void
    {
        $worktrees = Mockery::mock(WorktreeManager::class);
        $worktrees->shouldReceive('diff')->andReturn('diff');
        $worktrees->shouldReceive('changedFiles')->andReturn(['app/Http/Controllers/IkuController.php']);
        $worktrees->shouldReceive('head')->andReturn(str_repeat('a', 40));

        (new BatchReviewExecutionJob($run->id))->handle($agents, $worktrees, app(BatchQa::class));
    }

    public function test_crash_in_smoke_test_sends_code_back_to_developer_without_asking_qa(): void
    {
        Queue::fake();
        $this->fakeSmoke([self::CRASH, ['kind' => 'ok', 'method' => 'GET', 'uri' => '/', 'status' => 200]]);
        $run = $this->makeRun();
        $agents = Mockery::mock(AgentProfileClient::class);
        $agents->shouldNotReceive('sendMessage');

        $this->review($run, $agents);

        $finding = ExecutionFinding::query()->where('execution_run_id', $run->id)->sole();
        $this->assertSame('app/Http/Controllers/IkuController.php', $finding->file);
        $this->assertSame('high', $finding->severity);
        $this->assertStringContainsString('has no column named kode', $finding->issue);
        $this->assertSame('EXECUTING', $run->fresh()->status);
        $this->assertSame('REVISION', $run->tasks()->first()->status);
        Queue::assertPushed(ExecuteExecutionTaskJob::class);
    }

    public function test_passing_smoke_test_is_given_to_qa_and_closes_previous_smoke_findings(): void
    {
        Queue::fake();
        $this->fakeSmoke([['kind' => 'ok', 'method' => 'GET', 'uri' => '/iku', 'status' => 200, 'as_role' => 'admin']]);
        $run = $this->makeRun();
        $old = ExecutionFinding::query()->create([
            'execution_run_id' => $run->id, 'assignee' => 'backend-developer', 'severity' => 'high',
            'file' => 'app/Http/Controllers/IkuController.php', 'issue' => RuntimeSmoke::FINDING_PREFIX.' — GET /iku',
            'status' => ExecutionFinding::OPEN, 'round' => 1,
        ]);

        $prompts = [];
        $agents = Mockery::mock(AgentProfileClient::class);
        $agents->shouldReceive('sendMessage')->andReturnUsing(function (string $role, string $prompt) use (&$prompts) {
            $prompts[] = $prompt;

            return self::APPROVED;
        });

        $this->review($run, $agents);

        $this->assertStringContainsString('Uji jalan nyata (1 permintaan, 0 error)', $prompts[0]);
        $this->assertSame(ExecutionFinding::RESOLVED, $old->fresh()->status);
        $this->assertSame('COMPLETED', $run->fresh()->status);
    }

    public function test_regressions_are_behaviour_that_worked_before_and_fails_after(): void
    {
        $base = ['results' => [
            ['kind' => 'ok', 'method' => 'PATCH', 'uri' => '/profile', 'route_file' => 'app/Http/Controllers/ProfileController.php'],
            ['kind' => 'ok', 'method' => 'GET', 'uri' => '/users/12/edit', 'route_file' => 'app/Http/Controllers/UserController.php'],
            ['kind' => 'ok', 'method' => 'GET', 'uri' => '/laporan', 'route_file' => 'app/Http/Controllers/LaporanController.php'],
            ['kind' => 'validation', 'method' => 'POST', 'uri' => '/usulan', 'validation' => ['Tanggal salah.']],
        ]];
        $branch = ['results' => [
            ['kind' => 'validation', 'method' => 'PATCH', 'uri' => '/profile', 'validation' => ['Password lama salah.'], 'route_file' => 'app/Http/Controllers/ProfileController.php'],
            ['kind' => 'ok', 'method' => 'GET', 'uri' => '/users/13/edit'],
            ['kind' => 'validation', 'method' => 'POST', 'uri' => '/usulan', 'validation' => ['Tanggal salah.']],
        ]];

        $findings = app(RuntimeSmoke::class)->regressions($base, $branch);

        $this->assertCount(2, $findings);
        $this->assertSame('app/Http/Controllers/ProfileController.php', $findings[0]['file']);
        $this->assertStringStartsWith(RuntimeSmoke::REGRESSION_PREFIX, $findings[0]['instruction']);
        $this->assertStringContainsString('Password lama salah.', $findings[0]['instruction']);
        $this->assertSame('app/Http/Controllers/LaporanController.php', $findings[1]['file']);
        $this->assertStringContainsString('rute hilang', $findings[1]['instruction']);
    }

    public function test_regression_sends_code_back_to_developer_before_qa(): void
    {
        Queue::fake();
        $run = $this->makeRun();
        $this->directRunGit($run);
        $this->fakeSmoke(
            [['kind' => 'validation', 'method' => 'PATCH', 'uri' => '/profile', 'validation' => ['Password lama salah.'], 'route_file' => 'app/Http/Controllers/ProfileController.php']],
            baseResults: [['kind' => 'ok', 'method' => 'PATCH', 'uri' => '/profile', 'route_file' => 'app/Http/Controllers/ProfileController.php']],
        );
        $agents = Mockery::mock(AgentProfileClient::class);
        // Developer boleh menjawab temuan regresi (negosiasi), tetapi QA tidak dipanggil untuk menilai.
        $agents->shouldReceive('sendMessage')->withArgs(fn (string $role) => $role !== 'qa-engineer')->andReturn('{}');
        $agents->shouldNotReceive('sendMessage')->withArgs(fn (string $role) => $role === 'qa-engineer');

        $this->review($run, $agents);

        $finding = ExecutionFinding::query()->where('execution_run_id', $run->id)->sole();
        $this->assertStringStartsWith(RuntimeSmoke::REGRESSION_PREFIX, $finding->issue);
        $this->assertSame('EXECUTING', $run->fresh()->status);
        $this->assertSame('REVISION', $run->tasks()->first()->status);
        Queue::assertPushed(ExecuteExecutionTaskJob::class);
    }

    public function test_acceptance_tests_branch_through_worktree_and_refuses_regression(): void
    {
        $owner = User::factory()->create();
        $run = $this->makeRun();
        $run->forceFill(['status' => 'COMPLETED', 'completed_at' => now(), 'work_branch' => 'scrum/run-'.$run->id, 'base_branch' => 'main'])->save();
        $demo = Mockery::mock(DemoServerManager::class);
        $demo->shouldReceive('verify')->andReturn(['ok' => true, 'http' => 200, 'url' => 'http://127.0.0.1:9999/', 'error' => null, 'demo' => null]);
        $this->app->instance(DemoServerManager::class, $demo);
        $git = Mockery::mock(WorktreeManager::class);
        $git->shouldReceive('currentBranch')->andReturn('scrum/run-999');
        $git->shouldReceive('branchExists')->andReturn(true);
        $git->shouldReceive('changedFilesBetween')->andReturn(['app/Http/Controllers/ProfileController.php']);
        $this->app->instance(WorktreeManager::class, $git);
        $this->fakeSmoke(
            [],
            baseResults: [['kind' => 'ok', 'method' => 'PATCH', 'uri' => '/profile', 'route_file' => 'app/Http/Controllers/ProfileController.php']],
            branchAtRef: [['kind' => 'validation', 'method' => 'PATCH', 'uri' => '/profile', 'validation' => ['Password lama salah.'], 'route_file' => 'app/Http/Controllers/ProfileController.php']],
        );

        $this->actingAs($owner)
            ->post('/stories/'.$run->story->id.'/executions/'.$run->id.'/accept', ['status' => 'BERHASIL'])
            ->assertSessionHasErrors('acceptance');

        $this->assertFalse($run->story->fresh()->isAccepted());
    }

    public function test_smoke_test_that_cannot_run_does_not_block_qa(): void
    {
        Queue::fake();
        $this->fakeSmoke([], ran: false);
        $run = $this->makeRun();
        $agents = Mockery::mock(AgentProfileClient::class);
        $agents->shouldReceive('sendMessage')->once()->andReturn(self::APPROVED);

        $this->review($run, $agents);

        $this->assertSame('COMPLETED', $run->fresh()->status);
    }

    public function test_crashes_with_the_same_cause_become_one_finding(): void
    {
        $navigation = ['kind' => 'crash', 'method' => 'GET', 'status' => 500, 'error' => 'ViewException: Route [tahun.index] not defined.', 'file' => 'resources/views/layouts/navigation.blade.php'];
        $report = ['ran' => true, 'error' => null, 'roles' => [], 'results' => [
            $navigation + ['uri' => '/iku'],
            $navigation + ['uri' => '/iku/create'],
            self::CRASH,
        ]];

        $findings = app(RuntimeSmoke::class)->findings($report, ['app/Http/Controllers/IkuController.php']);

        $this->assertCount(2, $findings);
        $this->assertSame('resources/views/layouts/navigation.blade.php', $findings[0]['file']);
        $this->assertStringContainsString('GET /iku, GET /iku/create', $findings[0]['instruction']);
    }

    public function test_acceptance_is_refused_when_a_story_page_crashes(): void
    {
        $owner = User::factory()->create();
        $run = $this->makeRun();
        $run->forceFill(['status' => 'COMPLETED', 'completed_at' => now(), 'work_branch' => 'scrum/run-'.$run->id, 'base_branch' => 'main'])->save();
        $story = $run->story;

        $demo = Mockery::mock(DemoServerManager::class);
        $demo->shouldReceive('verify')->andReturn(['ok' => true, 'http' => 200, 'url' => 'http://127.0.0.1:9999/', 'error' => null, 'demo' => null]);
        $this->app->instance(DemoServerManager::class, $demo);
        $git = Mockery::mock(WorktreeManager::class);
        $git->shouldReceive('currentBranch')->andReturn('scrum/run-'.$run->id);
        $git->shouldReceive('branchExists')->andReturn(true);
        $git->shouldReceive('changedFilesBetween')->andReturn(['app/Http/Controllers/IkuController.php']);
        $this->app->instance(WorktreeManager::class, $git);
        $this->fakeSmoke([self::CRASH]);

        $this->actingAs($owner)
            ->post('/stories/'.$story->id.'/executions/'.$run->id.'/accept', ['status' => 'BERHASIL'])
            ->assertSessionHasErrors('acceptance');

        $this->assertFalse($story->fresh()->isAccepted());
    }

    public function test_project_facts_list_real_tables_and_columns(): void
    {
        $project = Project::query()->create([
            'name' => 'Facts', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel',
            'status' => 'BASELINE_READY', 'workspace_path' => storage_path('framework/testing'),
        ]);
        $facts = app(ProjectFacts::class);
        $database = $facts->databasePath($project);
        @mkdir(dirname($database), 0775, true);
        @unlink($database);
        $pdo = new PDO('sqlite:'.$database);
        $pdo->exec('CREATE TABLE ikus (id integer primary key, kode_iku varchar not null, nama_iku varchar not null, deskripsi text)');
        $pdo->exec('CREATE TABLE migrations (id integer primary key, migration varchar)');

        try {
            $schema = $facts->schema($project);
        } finally {
            @unlink($database);
        }

        $this->assertStringContainsString('- ikus: id integer, kode_iku varchar*, nama_iku varchar*, deskripsi text', $schema);
        $this->assertStringNotContainsString('migrations', $schema);
    }
}
