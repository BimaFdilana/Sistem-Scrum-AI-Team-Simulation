<?php

namespace Tests\Feature;

use App\Models\Actor;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TasksTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_owner_can_break_down_an_approved_story_into_a_backlog_task(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProject();
        $story = $this->createStory($project);
        $this->approveStoryForSprint($owner, $project, $story);
        $response = $this->actingAs($owner)->post('/stories/'.$story->id.'/tasks', [
            'title' => 'Implement login throttling',
            'description' => 'Limit repeated failed authentication attempts.',
            'assignee_profile' => 'backend-developer',
        ]);

        $response->assertRedirect('/stories/'.$story->id.'/tasks');
        $task = Task::query()->sole();
        $story->refresh();
        $this->assertSame($story->id, $task->story_id);
        $this->assertSame('BACKLOG', $task->status);
        $this->assertSame($story->revision, $task->story_revision);
        $this->assertSame('backend-developer', $task->assignee_profile);
    }

    public function test_owner_can_view_task_list_and_create_form_for_an_approved_story(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProject();
        $story = $this->createStory($project);
        $this->approveStoryForSprint($owner, $project, $story);

        $this->actingAs($owner)
            ->get('/stories/'.$story->id.'/tasks')
            ->assertOk()
            ->assertSee('Belum ada task breakdown');

        $this->actingAs($owner)
            ->get('/stories/'.$story->id.'/tasks/create')
            ->assertOk()
            ->assertSee('Simpan ke backlog');
    }

    public function test_task_cannot_be_created_for_a_story_without_current_scope_approval(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProject();
        $story = $this->createStory($project);

        $this->actingAs($owner)
            ->from('/stories/'.$story->id.'/tasks')
            ->post('/stories/'.$story->id.'/tasks', [
                'title' => 'Premature task',
                'assignee_profile' => 'backend-developer',
            ])
            ->assertRedirect('/stories/'.$story->id.'/tasks')
            ->assertSessionHasErrors('scope');

        $this->assertDatabaseCount('tasks', 0);
    }

    private function approveStoryForSprint(User $owner, Project $project, Story $story): void
    {
        $sprint = $project->sprints()->create([
            'backlog_id' => $project->backlog->id,
            'name' => 'Sprint 1',
            'goal' => 'Deliver a safe sign-in increment.',
            'goal_source' => 'AI_DRAFT',

            'status' => 'PLANNING',
            'revision' => 1,
        ]);
        $actor = Actor::query()->where('name', 'Administrator')->sole();
        $story->forceFill(['sprint_id' => $sprint->id])->save();

        $this->actingAs($owner)->put('/sprints/'.$sprint->id.'/scope', [
            'story_ids' => [$story->id],
            'story_details' => [$story->id => [
                'actor_id' => $actor->id,
                'purpose' => 'Deliver a safe sign-in flow.',
                'acceptance_criteria' => 'Guests must authenticate before accessing protected pages.',
                'status' => 'NOT_STARTED',
            ]],
        ])->assertRedirect('/sprints/'.$sprint->id);

        $this->actingAs($owner)->post('/sprints/'.$sprint->id.'/approve-scope')->assertRedirect('/sprints/'.$sprint->id);
    }

    private function createStory(Project $project): Story
    {
        return $project->stories()->create([
            'title' => 'Secure admin login',
            'description' => 'Owner can access the workspace safely.',
            'acceptance_criteria' => 'Guests are redirected to login and invalid credentials do not authenticate.',
            'priority' => 1,
            'scope_status' => 'NEEDS_REFINEMENT',
            'revision' => 1,
        ]);
    }

    private function createProject(): Project
    {
        return Project::query()->create([
            'name' => 'Demo project',
            'project_type' => 'NEW_PROJECT',
            'stack' => 'Laravel',
            'status' => 'SETUP_REQUIRED',
        ]);
    }
}
