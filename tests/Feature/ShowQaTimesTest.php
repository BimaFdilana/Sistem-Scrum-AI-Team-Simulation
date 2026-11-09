<?php

namespace Tests\Feature;

use App\Models\ExecutionRun;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresi dari log live (19:10 WIB): halaman eksekusi 500 "format() on string" begitu QA batch
 * mengisi qa_started_at/qa_finished_at, karena kolomnya tidak di-cast ke tanggal.
 */
class ShowQaTimesTest extends TestCase
{
    use RefreshDatabase;

    public function test_execution_page_renders_when_batch_qa_has_timestamps(): void
    {
        $project = Project::query()->create(['name' => 'SQ', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'BASELINE_READY']);
        $story = Story::query()->create(['project_id' => $project->id, 'title' => 'S', 'estimate_points' => 3]);
        $run = ExecutionRun::query()->create([
            'story_id' => $story->id, 'requested_by' => User::factory()->create()->id, 'status' => 'QA_REVIEW', 'mode' => 'direct', 'qa_mode' => 'batch',
            'qa_started_at' => now()->subMinutes(2), 'qa_finished_at' => now(),
        ]);
        $run->tasks()->create(['title' => 'T', 'description' => 'D', 'assignee' => 'backend-developer', 'status' => 'QA_PENDING']);

        $this->assertInstanceOf(CarbonInterface::class, $run->fresh()->qa_started_at);
        $this->actingAs(User::factory()->create())->get(route('stories.executions.show', [$story, $run]))->assertOk()->assertSee('Mulai ');
    }
}
