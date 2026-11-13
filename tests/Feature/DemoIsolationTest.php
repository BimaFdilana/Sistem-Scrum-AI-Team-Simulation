<?php

namespace Tests\Feature;

use App\Models\ExecutionRun;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use App\Support\Demo\DemoServerManager;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Demo memakai folder kode sendiri: tidak pernah menyajikan eksekusi yang sedang
 * berjalan, dan database demo dibangun ulang bila memuat migrasi sisa eksekusi gagal.
 */
class DemoIsolationTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private string $repo;

    private function git(string ...$args): string
    {
        $process = new Process(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', ...$args], $this->repo);
        $process->mustRun();

        return trim($process->getOutput());
    }

    private function project(): Project
    {
        $this->root = storage_path('framework/testing/demo-'.uniqid());
        $this->repo = $this->root.'/baseline';
        mkdir($this->repo.'/database/migrations', 0700, true);
        mkdir($this->repo.'/vendor', 0700, true);
        file_put_contents($this->repo.'/.gitignore', "/vendor\n");
        file_put_contents($this->repo.'/database/migrations/2024_01_01_000000_create_units_table.php', '<?php');
        file_put_contents($this->repo.'/app.txt', 'utama');
        $this->git('init', '-q', '-b', 'main');
        $this->git('add', '.');
        $this->git('commit', '-q', '-m', 'awal');

        return Project::query()->create(['name' => 'D', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'BASELINE_READY', 'workspace_path' => $this->repo]);
    }

    private function runOn(Project $project, string $branch, string $status): ExecutionRun
    {
        $story = Story::create(['project_id' => $project->id, 'title' => 'S', 'estimate_points' => 3]);

        return ExecutionRun::query()->create(['story_id' => $story->id, 'requested_by' => User::factory()->create()->id, 'status' => $status, 'mode' => 'direct', 'work_branch' => $branch, 'base_branch' => 'main']);
    }

    public function test_running_execution_is_never_served_by_the_demo(): void
    {
        $project = $this->project();
        $this->git('checkout', '-q', '-b', 'scrum/run-5');
        file_put_contents($this->repo.'/app.txt', 'setengah jadi');
        $this->git('commit', '-qam', 'wip');
        $this->runOn($project, 'scrum/run-5', 'EXECUTING');

        $demos = app(DemoServerManager::class);
        $this->assertSame('main', $demos->demoTarget($project)['ref']);

        $tree = $demos->syncDemoTree($project);
        $this->assertSame($this->root.'/demo', $tree);
        $this->assertSame('utama', file_get_contents($tree.'/app.txt'));
        $this->assertTrue(is_link($tree.'/vendor'));
        $this->assertSame('setengah jadi', file_get_contents($this->repo.'/app.txt')); // folder kerja agen tidak disentuh
    }

    public function test_completed_result_awaiting_decision_is_served_then_back_to_main_after_gagal(): void
    {
        $project = $this->project();
        $this->git('checkout', '-q', '-b', 'scrum/run-6');
        file_put_contents($this->repo.'/app.txt', 'hasil selesai');
        $this->git('commit', '-qam', 'hasil');
        $run = $this->runOn($project, 'scrum/run-6', 'COMPLETED');

        $demos = app(DemoServerManager::class);
        $this->assertSame('scrum/run-6', $demos->demoTarget($project)['ref']);
        $this->assertSame('hasil selesai', file_get_contents($demos->syncDemoTree($project).'/app.txt'));

        $run->story->forceFill(['acceptance_status' => 'GAGAL'])->save();
        $this->assertSame('main', $demos->demoTarget($project)['ref']);
        $this->assertSame('utama', file_get_contents($demos->syncDemoTree($project).'/app.txt'));
    }

    public function test_demo_database_with_leftover_migrations_is_detected(): void
    {
        $project = $this->project();
        $database = $this->root.'/demo.sqlite';
        $pdo = new \PDO('sqlite:'.$database);
        $pdo->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT, batch INTEGER)');
        $pdo->exec("INSERT INTO migrations (migration, batch) VALUES ('2024_01_01_000000_create_units_table', 1), ('2024_01_01_000000_create_pagu_units_table', 2)");

        $orphans = app(DemoServerManager::class)->orphanMigrations($database, $this->repo);

        $this->assertSame(['2024_01_01_000000_create_pagu_units_table'], $orphans);
        $this->assertNotNull($project);
    }

    public function test_migration_with_same_name_but_changed_content_is_detected(): void
    {
        $this->project();
        $database = $this->root.'/demo.sqlite';
        $pdo = new \PDO('sqlite:'.$database);
        $pdo->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT, batch INTEGER)');
        $pdo->exec("INSERT INTO migrations (migration, batch) VALUES ('2024_01_01_000000_create_units_table', 1)");
        $pdo->exec('CREATE TABLE scrumspace_migration_hashes (migration TEXT PRIMARY KEY, hash TEXT NOT NULL)');
        $pdo->exec("INSERT INTO scrumspace_migration_hashes VALUES ('2024_01_01_000000_create_units_table', 'versi-lama')");

        $problems = app(DemoServerManager::class)->orphanMigrations($database, $this->repo);

        $this->assertSame(['2024_01_01_000000_create_units_table (isi berubah)'], $problems);
    }

    public function test_demo_prune_is_scheduled(): void
    {
        $events = collect(app(Schedule::class)->events());

        $this->assertTrue($events->contains(fn ($event) => $event->description === 'demo-prune'));
    }
}
