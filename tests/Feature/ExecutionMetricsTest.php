<?php

namespace Tests\Feature;

use App\Models\ExecutionFinding;
use App\Models\ExecutionRun;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ExecutionMetricsTest extends TestCase
{
    use RefreshDatabase;

    private function accepted(Project $project, Sprint $sprint, string $title, int $points, string $acceptance): ExecutionRun
    {
        $story = Story::query()->create([
            'project_id' => $project->id, 'sprint_id' => $sprint->id, 'title' => $title, 'estimate_points' => $points, 'acceptance_status' => $acceptance,
        ]);
        $run = ExecutionRun::query()->create([
            'story_id' => $story->id, 'requested_by' => User::factory()->create()->id, 'status' => 'COMPLETED',
            'qa_rounds' => 2, 'completed_at' => now()->addMinutes(30),
        ]);
        $run->tasks()->create(['title' => 'T', 'description' => 'D', 'assignee' => 'backend-developer', 'status' => 'APPROVED', 'revision_count' => 2]);

        return $run;
    }

    public function test_json_metrics_report_velocity_revisions_and_findings(): void
    {
        $project = Project::query()->create(['name' => 'M', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'BASELINE_READY']);
        $sprint = Sprint::query()->create(['project_id' => $project->id, 'backlog_id' => $project->backlog->id, 'name' => 'Sprint 1', 'status' => 'PLANNING', 'revision' => 1]);
        $run = $this->accepted($project, $sprint, 'Diterima', 5, 'BERHASIL');
        $this->accepted($project, $sprint, 'Ditolak', 3, 'GAGAL');
        ExecutionFinding::query()->create(['execution_run_id' => $run->id, 'file' => 'a.php', 'issue' => 'x', 'status' => 'DISMISSED']);

        $this->artisan('execution:metrics', ['--format' => 'json'])->assertSuccessful();
        Artisan::call('execution:metrics', ['--format' => 'json']);
        $data = json_decode(Artisan::output(), true);

        $this->assertCount(2, $data['story']);
        $accepted = collect($data['story'])->firstWhere('diterima', true);
        $this->assertSame(5, $accepted['poin']);
        $this->assertSame(2, $accepted['putaran_qa']);
        $this->assertSame(2, $accepted['revisi_task']);
        $this->assertSame(1, $accepted['temuan_ditutup_sm']);
        $this->assertSame(30, $accepted['menit']);
        $this->assertSame([['sprint' => 'Sprint 1', 'story_diterima' => 1, 'velocity_poin' => 5]], $data['velocity']);
    }

    public function test_reports_when_nothing_was_executed(): void
    {
        $this->artisan('execution:metrics')->expectsOutputToContain('Belum ada story')->assertSuccessful();
    }
}
