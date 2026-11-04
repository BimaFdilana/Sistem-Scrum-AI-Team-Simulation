<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Support\Demo\DemoServerManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class DemoSqliteEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_environment_forces_local_sqlite_and_file_drivers(): void
    {
        $project = Project::query()->create([
            'name' => 'Demo SQLite',
            'project_type' => 'NEW_PROJECT',
            'stack' => 'Laravel',
            'status' => 'BASELINE_READY',
            'workspace_path' => base_path(),
        ]);

        // File non-kosong = migrasi tidak dijalankan pada tes ini.
        $dir = storage_path('app/demo-db');
        @mkdir($dir, 0775, true);
        $db = $dir.'/project-'.$project->id.'.sqlite';
        file_put_contents($db, 'x');

        $manager = app(DemoServerManager::class);
        $method = new ReflectionMethod($manager, 'demoEnvironment');
        $method->setAccessible(true);

        $env = $method->invoke($manager, base_path(), $project, storage_path('logs/demo-test.log'));

        $this->assertSame('sqlite', $env['DB_CONNECTION']);
        $this->assertSame($db, $env['DB_DATABASE']);
        $this->assertSame('file', $env['SESSION_DRIVER']);
        $this->assertSame('file', $env['CACHE_DRIVER']);
        $this->assertSame('sync', $env['QUEUE_CONNECTION']);
        $this->assertStringContainsString('demo-db', $env['DB_DATABASE']);

        @unlink($db);
    }
}
