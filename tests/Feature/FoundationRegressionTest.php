<?php

namespace Tests\Feature;

use App\Models\Actor;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Story;
use App\Models\StoryEstimationAgentRun;
use App\Models\StoryEstimationRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FoundationRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_activity_displays_saved_estimation_with_its_story(): void
    {
        [$owner, $project, $sprint, $story] = $this->makeEstimatedStory();
        $run = StoryEstimationRun::query()->create([
            'story_id' => $story->id,
            'requested_by' => $owner->id,
            'story_revision' => 1,
            'status' => 'COMPLETED',
            'summary' => 'Hasil refinement tersimpan.',
        ]);
        $agentRun = StoryEstimationAgentRun::query()->create([
            'story_estimation_run_id' => $run->id,
            'profile_slug' => 'backend-developer',
            'round' => 1,
            'status' => 'COMPLETED',
            'estimate_points' => 8,
            'rationale' => 'Validasi input dan penyimpanan memerlukan pengujian.',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $this->actingAs($owner)->get('/agents/backend-developer/activity')
            ->assertOk()
            ->assertSee('US-01')
            ->assertSee($story->title)
            ->assertSee($agentRun->rationale);

        $this->assertSame($run->id, $agentRun->fresh()->estimationRun->id);
        $this->assertSame($story->id, $agentRun->fresh()->estimationRun->story->id);
    }

    #[DataProvider('storyEditPaths')]
    public function test_story_edits_clear_points_and_preserve_estimation_history(string $path, string $field): void
    {
        [$owner, $project, $sprint, $story] = $this->makeEstimatedStory();
        $run = StoryEstimationRun::query()->create([
            'story_id' => $story->id,
            'requested_by' => $owner->id,
            'story_revision' => 1,
            'status' => 'COMPLETED',
            'approved_points' => 8,
            'approved_by' => $owner->id,
            'approved_at' => now(),
        ]);
        $payload = [
            'title' => $story->title,
            'actor_id' => $story->actor_id,
            'sprint_purpose' => $story->sprint_purpose,
            'acceptance_criteria' => $story->acceptance_criteria,
        ];
        $payload[$field] = match ($field) {
            'title' => 'Kelola data project terbaru',
            'actor_id' => Actor::query()->create(['name' => 'Auditor Regression', 'is_active' => true])->id,
            'sprint_purpose' => 'Data perubahan dapat dipantau oleh PO.',
            'acceptance_criteria' => 'Kode data harus unik dan data dapat dicari.',
        };

        if ($path === 'story') {
            $this->actingAs($owner)->patch('/stories/'.$story->id, $payload)
                ->assertSessionHasNoErrors()->assertRedirect('/projects/'.$project->id.'/sprint-backlog');
        } else {
            $this->actingAs($owner)->put('/sprints/'.$sprint->id.'/scope', [
                'story_ids' => [$story->id],
                'story_details' => [$story->id => [
                    'actor_id' => $payload['actor_id'],
                    'purpose' => $payload['sprint_purpose'],
                    'acceptance_criteria' => $payload['acceptance_criteria'],
                ]],
            ])->assertSessionHasNoErrors()->assertRedirect('/sprints/'.$sprint->id);
        }

        $updated = $story->fresh();
        $this->assertNull($updated->estimate_points);
        $this->assertSame(2, $updated->revision);
        $this->assertSame('NEEDS_REFINEMENT', $updated->scope_status);
        $this->assertNull($updated->scope_approved_revision);
        $this->assertNull($updated->scope_approved_by);
        $this->assertNull($updated->scope_approved_at);
        $this->assertSame(1, $run->fresh()->story_revision);
        $this->assertSame(8, $run->fresh()->approved_points);
        $this->assertSame(1, StoryEstimationRun::query()->count());
        $this->actingAs($owner)->get('/projects/'.$project->id.'/sprint-backlog')
            ->assertOk()->assertDontSee('8 poin Fibonacci')->assertSee('kedaluwarsa');
    }

    public function test_saving_points_without_a_story_change_keeps_the_new_value(): void
    {
        [$owner, $project, $sprint, $story] = $this->makeEstimatedStory();

        $story->forceFill(['estimate_points' => 5])->save();

        $this->assertSame(5, $story->fresh()->estimate_points);
        $this->assertSame(1, $story->fresh()->revision);
    }

    public function test_unchanged_story_submission_keeps_points_and_approval(): void
    {
        [$owner, $project, $sprint, $story] = $this->makeEstimatedStory();

        $this->actingAs($owner)->patch('/stories/'.$story->id, [
            'title' => $story->title,
            'actor_id' => $story->actor_id,
            'sprint_purpose' => $story->sprint_purpose,
            'acceptance_criteria' => $story->acceptance_criteria,
        ])->assertSessionHasNoErrors()->assertRedirect('/projects/'.$project->id.'/sprint-backlog');

        $this->assertSame(8, $story->fresh()->estimate_points);
        $this->assertSame(1, $story->fresh()->revision);
        $this->assertSame('APPROVED', $story->fresh()->scope_status);
        $this->assertSame(1, $story->fresh()->scope_approved_revision);
    }

    public function test_invalid_story_submission_does_not_clear_saved_points(): void
    {
        [$owner, $project, $sprint, $story] = $this->makeEstimatedStory();

        $this->actingAs($owner)->patch('/stories/'.$story->id, [
            'title' => 'Judul tidak disimpan karena input tidak valid',
            'actor_id' => $story->actor_id,
            'sprint_purpose' => $story->sprint_purpose,
            'acceptance_criteria' => '',
        ])->assertSessionHasErrors('acceptance_criteria');

        $this->assertSame(8, $story->fresh()->estimate_points);
        $this->assertSame(1, $story->fresh()->revision);
        $this->assertSame('Kelola data project', $story->fresh()->title);
    }

    public static function storyEditPaths(): array
    {
        return [
            'story feature' => ['story', 'title'],
            'story actor' => ['story', 'actor_id'],
            'story purpose' => ['story', 'sprint_purpose'],
            'story criteria' => ['story', 'acceptance_criteria'],
            'sprint actor' => ['sprint', 'actor_id'],
            'sprint purpose' => ['sprint', 'sprint_purpose'],
            'sprint criteria' => ['sprint', 'acceptance_criteria'],
        ];
    }

    /** @return array{User, Project, Sprint, Story} */
    private function makeEstimatedStory(): array
    {
        $owner = User::factory()->create();
        $project = Project::query()->create([
            'name' => 'Foundation Regression',
            'project_type' => 'NEW_PROJECT',
            'stack' => 'Laravel',
            'status' => 'SETUP_REQUIRED',
        ]);
        $sprint = Sprint::query()->create([
            'project_id' => $project->id,
            'backlog_id' => $project->backlog->id,
            'name' => 'Sprint Foundation',
            'status' => 'PLANNING',
            'revision' => 1,
        ]);
        $actor = Actor::query()->where('name', 'Administrator')->firstOrFail();
        $story = Story::query()->create([
            'project_id' => $project->id,
            'sprint_id' => $sprint->id,
            'actor_id' => $actor->id,
            'user_story_key' => 'US-01',
            'title' => 'Kelola data project',
            'sprint_purpose' => 'Data dapat dipantau oleh PO.',
            'acceptance_criteria' => 'Data valid dapat disimpan dan ditampilkan.',
            'estimate_points' => 8,
            'scope_status' => 'APPROVED',
            'revision' => 1,
        ]);
        $story->forceFill([
            'scope_approved_revision' => 1,
            'scope_approved_by' => $owner->id,
            'scope_approved_at' => now(),
        ])->save();

        return [$owner, $project, $sprint, $story];
    }
}
