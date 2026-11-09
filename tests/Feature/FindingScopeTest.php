<?php

namespace Tests\Feature;

use App\Models\ExecutionFinding;
use App\Models\ExecutionRun;
use App\Models\PermissionRequest;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use App\Support\Execution\BatchQa;
use App\Support\Execution\FindingDialogue;
use App\Support\Execution\TaskFileScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresi pengukuran nyata (US-05 run #12): temuan QA di routes/web.php diteruskan ke task
 * yang cakupannya hanya sebuah controller, dan developer dilarang menyentuh file lain,
 * sehingga temuan yang sama berulang di semua putaran.
 */
class FindingScopeTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(): array
    {
        $project = Project::query()->create(['name' => 'FS', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'BASELINE_READY']);
        $story = Story::query()->create(['project_id' => $project->id, 'title' => 'S', 'estimate_points' => 3]);
        $run = ExecutionRun::query()->create(['story_id' => $story->id, 'requested_by' => User::factory()->create()->id, 'status' => 'QA_REVIEW', 'mode' => 'direct', 'qa_mode' => 'batch']);
        $controller = $run->tasks()->create(['title' => 'Controller login', 'description' => 'D', 'assignee' => 'backend-developer', 'status' => 'QA_PENDING', 'sort_order' => 0]);
        $routes = $run->tasks()->create(['title' => 'Route dashboard', 'description' => 'D', 'assignee' => 'backend-developer', 'status' => 'QA_PENDING', 'sort_order' => 1]);
        PermissionRequest::query()->create([
            'execution_run_id' => $run->id, 'title' => 'Izin', 'status' => 'APPROVED', 'requested_by' => $run->requested_by,
            'payload' => ['tasks' => [
                ['title' => 'Controller login', 'assignee' => 'backend-developer', 'files' => ['app/Http/Controllers/LoginController.php']],
                ['title' => 'Route dashboard', 'assignee' => 'backend-developer', 'files' => ['routes/web.php']],
            ]],
        ]);

        return [$run, $controller, $routes];
    }

    public function test_finding_goes_to_the_task_whose_scope_contains_the_file(): void
    {
        [$run, $controller, $routes] = $this->makeRun();

        $records = app(FindingDialogue::class)->record($run, [
            ['file' => 'routes/web.php', 'instruction' => 'Ubah view dashboard', 'verify' => ''],
        ], 1, app(BatchQa::class), $run->tasks()->get());

        $this->assertSame($routes->id, $records->first()->execution_task_id, 'bukan task controller yang kebetulan pertama');
    }

    public function test_open_finding_file_is_added_to_the_task_scope_so_the_fix_is_allowed(): void
    {
        [$run, $controller] = $this->makeRun();
        ExecutionFinding::query()->create([
            'execution_run_id' => $run->id, 'execution_task_id' => $controller->id, 'assignee' => 'backend-developer',
            'file' => 'resources/views/verifikator_dashboard.blade.php', 'issue' => 'x', 'status' => 'OPEN', 'round' => 1,
        ]);

        $scope = (new TaskFileScope)->filesFor($run, $controller);

        $this->assertContains('app/Http/Controllers/LoginController.php', $scope);
        $this->assertContains('resources/views/verifikator_dashboard.blade.php', $scope);
    }

    public function test_resolved_finding_no_longer_widens_the_scope(): void
    {
        [$run, $controller] = $this->makeRun();
        ExecutionFinding::query()->create([
            'execution_run_id' => $run->id, 'execution_task_id' => $controller->id, 'assignee' => 'backend-developer',
            'file' => 'routes/auth.php', 'issue' => 'x', 'status' => 'RESOLVED', 'round' => 1,
        ]);

        $this->assertSame(['app/Http/Controllers/LoginController.php'], (new TaskFileScope)->filesFor($run, $controller));
    }
}
