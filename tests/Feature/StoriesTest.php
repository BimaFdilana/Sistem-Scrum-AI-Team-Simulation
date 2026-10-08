<?php

namespace Tests\Feature;

use App\Models\Actor;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_user_story_form_and_persistence_follow_the_example_columns(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProject();
        $sprint = $this->createSprint($project, 'Sprint 1');
        $actor = Actor::query()->where('name', 'Administrator')->sole();

        $this->actingAs($owner)->get('/sprints/'.$sprint->id.'/stories/create')
            ->assertOk()->assertSee('Sprint ID')->assertSee('Story ID')
            ->assertSee('Sebagai [Aktor]')->assertSee('Saya Ingin [Fitur]')
            ->assertSee('Agar [Tujuan]')->assertSee('Acceptance Criteria')
            ->assertDontSee('start_date')->assertDontSee('end_date');

        $this->actingAs($owner)->post('/sprints/'.$sprint->id.'/stories', [
            'title' => 'Mengelola data Unit Kerja dan akun belanja',
            'actor_id' => $actor->id,
            'sprint_purpose' => 'Sistem mengenali hierarki pengusul anggaran.',
            'acceptance_criteria' => "Tersedia fitur CRUD data unit kerja.\nKode unit kerja tidak boleh duplikat.",
        ])->assertRedirect('/projects/'.$project->id.'/sprint-backlog');

        $story = Story::query()->sole();
        $this->assertSame($sprint->id, $story->sprint_id);
        $this->assertSame('US-01', $story->display_id);
        $this->assertSame($actor->id, $story->actor_id);
        $this->assertSame('Mengelola data Unit Kerja dan akun belanja', $story->title);
        $this->assertSame('Sistem mengenali hierarki pengusul anggaran.', $story->sprint_purpose);
        $this->assertSame("Tersedia fitur CRUD data unit kerja.\nKode unit kerja tidak boleh duplikat.", $story->acceptance_criteria);
    }

    public function test_story_ids_are_sequential_across_sprints_in_the_project(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProject();
        $actor = Actor::query()->where('name', 'Administrator')->sole();
        $first = $this->createSprint($project, 'Sprint 1');
        $second = $this->createSprint($project, 'Sprint 2');

        foreach ([[$first, 'Story one'], [$second, 'Story two']] as [$sprint, $feature]) {
            $this->actingAs($owner)->post('/sprints/'.$sprint->id.'/stories', [
                'title' => $feature,
                'actor_id' => $actor->id,
                'sprint_purpose' => 'Mencapai hasil yang dapat diverifikasi.',
                'acceptance_criteria' => 'Hasil memenuhi kriteria bisnis yang disepakati.',
            ])->assertRedirect('/projects/'.$project->id.'/sprint-backlog');
        }

        $this->assertSame(['US-01', 'US-02'], Story::query()->orderBy('id')->get()->map->display_id->all());
    }

    public function test_incomplete_story_details_are_rejected(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProject();
        $sprint = $this->createSprint($project, 'Sprint 1');

        $this->actingAs($owner)->from('/projects/'.$project->id.'/sprint-backlog')
            ->post('/sprints/'.$sprint->id.'/stories', ['title' => 'Feature only'])
            ->assertRedirect('/projects/'.$project->id.'/sprint-backlog')
            ->assertSessionHasErrors(['actor_id', 'sprint_purpose', 'acceptance_criteria']);
        $this->assertDatabaseCount('stories', 0);
    }

    public function test_editing_any_user_story_field_invalidates_story_and_sprint_approval(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProject();
        $sprint = $this->createSprint($project, 'Sprint 1');
        $actor = Actor::query()->where('name', 'Administrator')->sole();
        $story = $project->stories()->create([
            'sprint_id' => $sprint->id,
            'user_story_key' => 'US-01',
            'actor_id' => $actor->id,
            'title' => 'Original feature',
            'sprint_purpose' => 'Original purpose.',
            'acceptance_criteria' => 'Original acceptance criteria are clear.',
            'scope_status' => 'APPROVED',
            'revision' => 4,
            'scope_approved_revision' => 4,
            'scope_approved_by' => $owner->id,
            'scope_approved_at' => now(),
        ]);
        $sprint->forceFill(['scope_approved_revision' => 1, 'scope_approved_by' => $owner->id, 'scope_approved_at' => now()])->save();

        $this->actingAs($owner)->patch('/stories/'.$story->id, [
            'actor_id' => $actor->id,
            'title' => 'Revised feature',
            'sprint_purpose' => 'Revised purpose.',
            'acceptance_criteria' => 'Revised acceptance criteria are clear.',
        ])->assertRedirect('/projects/'.$project->id.'/sprint-backlog');

        $story->refresh();
        $this->assertSame(5, $story->revision);
        $this->assertSame('NEEDS_REFINEMENT', $story->scope_status);
        $this->assertNull($story->scope_approved_revision);
        $this->assertNull($story->scope_approved_by);
        $this->assertNull($story->scope_approved_at);
        $this->assertNull($sprint->fresh()->scope_approved_revision);
    }

    public function test_story_in_active_sprint_cannot_be_edited(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProject();
        $sprint = $this->createSprint($project, 'Sprint aktif', 'ACTIVE');
        $actor = Actor::query()->where('name', 'Administrator')->sole();
        $story = $project->stories()->create([
            'sprint_id' => $sprint->id,
            'actor_id' => $actor->id,
            'title' => 'Committed feature',
            'sprint_purpose' => 'A committed purpose.',
            'acceptance_criteria' => 'The PO approved this criterion.',
            'scope_status' => 'APPROVED',
            'revision' => 1,
        ]);

        $this->actingAs($owner)->patch('/stories/'.$story->id, [
            'actor_id' => $actor->id,
            'title' => 'Unreviewed feature change',
            'sprint_purpose' => 'A different purpose.',
            'acceptance_criteria' => 'A different criterion was proposed.',
        ])->assertRedirect('/projects/'.$project->id.'/sprint-backlog')->assertSessionHasErrors('story');

        $this->assertSame('Committed feature', $story->fresh()->title);
    }

    public function test_sprint_backlog_page_contains_nested_story_form_and_no_legacy_separate_menus(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProject();
        $sprint = $this->createSprint($project, 'Sprint 1');
        $actor = Actor::query()->where('name', 'Administrator')->sole();
        $project->stories()->create([
            'sprint_id' => $sprint->id, 'actor_id' => $actor->id, 'user_story_key' => 'US-01',
            'title' => 'Feature example', 'sprint_purpose' => 'Purpose example.',
            'acceptance_criteria' => 'A valid acceptance criterion example.', 'revision' => 1,
        ]);

        $this->actingAs($owner)->get('/projects/'.$project->id.'/sprint-backlog')
            ->assertOk()->assertSee('Sprint Backlog')->assertSee('Sprint 1')->assertSee('US-01')
            ->assertSee('Acceptance Criteria')->assertSee('name="sprint_purpose"', false)
            ->assertDontSee('Product backlog')->assertDontSee('>Sprints</a>', false)
            ->assertDontSee('name="start_date"', false)->assertDontSee('name="end_date"', false);
    }

    public function test_guest_cannot_open_a_project_sprint_backlog(): void
    {
        $project = $this->createProject();
        $this->get('/projects/'.$project->id.'/sprint-backlog')->assertRedirect('/login');
    }

    private function createSprint(Project $project, string $name, string $status = 'PLANNING'): Sprint
    {
        return $project->sprints()->create([
            'backlog_id' => $project->backlog->id,
            'name' => $name,
            'status' => $status,
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
