<?php

namespace Tests\Feature;

use App\Jobs\ScaffoldLaravelProjectJob;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProjectsTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_owner_can_create_a_new_project_and_scaffold_is_dispatched(): void
    {
        Queue::fake();
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->post('/projects', [
            'name' => 'Workspace Demo',
            'description' => 'A fresh codebase for a demo.',
            'project_type' => 'NEW_PROJECT',
            'stack' => 'Laravel',
        ]);

        $project = Project::query()->sole();
        $response->assertRedirect('/projects/'.$project->id);
        $this->assertSame('NEW_PROJECT', $project->project_type);
        $this->assertSame('Laravel', $project->stack);
        $this->assertSame('SCAFFOLDING', $project->status);
        Queue::assertPushed(ScaffoldLaravelProjectJob::class, fn ($job) => $job->projectId === $project->id);
    }

    public function test_existing_project_requires_a_github_repository_url(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->from('/projects/create')->post('/projects', [
            'name' => 'Missing Source',
            'project_type' => 'EXISTING_PROJECT',
        ])->assertRedirect('/projects/create')->assertSessionHasErrors('github_repository_url');

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_sidebar_has_a_single_projects_entry_and_no_separate_sprint_backlog_link(): void
    {
        $owner = User::factory()->create();
        $project = Project::query()->create([
            'name' => 'Selector project',
            'project_type' => 'NEW_PROJECT',
            'stack' => 'Laravel',
            'status' => 'SCAFFOLDING',
        ]);

        $this->actingAs($owner)
            ->get('/projects')
            ->assertOk()
            ->assertSee(route('projects.show', $project), false)
            ->assertSee('Setiap project punya Sprint Backlog')
            ->assertDontSee('Product backlog')
            ->assertDontSee('>Sprints</a>', false)
            ->assertDontSee('>Sprint Backlog</a>', false);
    }
}
