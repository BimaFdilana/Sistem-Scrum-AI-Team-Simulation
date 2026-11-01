<?php

namespace Tests\Feature;

use App\Jobs\ExecuteExecutionTaskJob;
use App\Models\ExecutionRun;
use App\Models\PermissionRequest;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class ExecutionFileScopeTest extends TestCase
{
    use RefreshDatabase;

    private function scopeFor(ExecutionRun $run, $task, ?string $projectName): string
    {
        $method = new ReflectionMethod(ExecuteExecutionTaskJob::class, 'fileScopeSection');
        $method->setAccessible(true);

        return $method->invoke(new ExecuteExecutionTaskJob($run->id, $task->id), $run, $task, $projectName);
    }

    private function makeRun(): array
    {
        $owner = User::factory()->create();
        $project = Project::query()->create([
            'name' => 'Laundry Laravel (Uji)', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel',
            'status' => 'BASELINE_READY', 'workspace_path' => storage_path('framework/testing'),
        ]);
        $story = Story::query()->create(['project_id' => $project->id, 'title' => 'S', 'estimate_points' => 5]);
        $run = ExecutionRun::query()->create(['story_id' => $story->id, 'requested_by' => $owner->id, 'status' => 'EXECUTING']);

        return [$run, $project];
    }

    public function test_scope_uses_approved_permission_payload_and_filters_unsafe_paths(): void
    {
        [$run] = $this->makeRun();
        $task = $run->tasks()->create([
            'title' => 'Tambahkan kolom status', 'description' => 'KERJAKAN x',
            'assignee' => 'backend-developer', 'status' => 'PENDING', 'sort_order' => 0,
        ]);
        PermissionRequest::query()->create([
            'execution_run_id' => $run->id, 'title' => 'Izin eksekusi', 'status' => 'APPROVED',
            'requested_by' => $run->requested_by,
            'payload' => ['tasks' => [
                ['title' => 'Tambahkan kolom status', 'assignee' => 'backend-developer', 'files' => [
                    'config/order_status.php', 'app/Models/Order.php', '../evil.php', '/abs/path.php',
                ]],
            ]],
        ]);

        $section = $this->scopeFor($run, $task, 'Laundry Laravel (Uji)');

        $this->assertStringContainsString('PROJECT YANG DIKERJAKAN: Laundry Laravel (Uji)', $section);
        $this->assertStringContainsString('config/order_status.php', $section);
        $this->assertStringContainsString('app/Models/Order.php', $section);
        $this->assertStringNotContainsString('evil.php', $section);
        $this->assertStringNotContainsString('/abs/path.php', $section);
        $this->assertStringContainsString('DILARANG mengubah file di luar daftar ini', $section);
    }

    public function test_scope_falls_back_to_file_section_in_description(): void
    {
        [$run] = $this->makeRun();
        $task = $run->tasks()->create([
            'title' => 'Tambahkan kolom status',
            'description' => "KERJAKAN: tambah kolom.\nFILE: resources/views/orders/index.blade.php, public/css/orders.css\nHASIL: kolom tampil.\nSELESAI BILA: kolom ada.",
            'assignee' => 'frontend-developer', 'status' => 'PENDING', 'sort_order' => 0,
        ]);

        $section = $this->scopeFor($run, $task, 'Laundry Laravel (Uji)');

        $this->assertStringContainsString('resources/views/orders/index.blade.php', $section);
        $this->assertStringContainsString('public/css/orders.css', $section);
        $this->assertStringNotContainsString('HASIL', $section);
    }
}
