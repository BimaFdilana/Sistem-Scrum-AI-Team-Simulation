<?php

namespace Tests\Feature;

use App\Models\ExecutionFinding;
use App\Models\ExecutionRun;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowFindingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_execution_page_lists_findings_with_both_sides(): void
    {
        $project = Project::query()->create(['name' => 'SF', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'BASELINE_READY']);
        $story = Story::query()->create(['project_id' => $project->id, 'title' => 'S', 'estimate_points' => 3]);
        $run = ExecutionRun::query()->create(['story_id' => $story->id, 'requested_by' => User::factory()->create()->id, 'status' => 'EXECUTING']);
        ExecutionFinding::query()->create([
            'execution_run_id' => $run->id, 'file' => 'app/Foo.php', 'issue' => 'Validasi hilang', 'status' => 'OPEN',
            'dev_stance' => 'disagree', 'dev_reason' => 'ada di middleware', 'sm_winner' => 'qa', 'sm_reason' => 'criteria jelas',
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('stories.executions.show', [$story, $run]))
            ->assertOk()
            ->assertSee('Validasi hilang')
            ->assertSee('ada di middleware')
            ->assertSee('QA benar');
    }
}
