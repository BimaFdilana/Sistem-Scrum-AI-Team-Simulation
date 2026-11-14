<?php

namespace Tests\Feature;

use App\Models\Actor;
use App\Models\Backlog;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BacklogHierarchyTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_has_one_named_sprint_backlog_and_unified_project_container(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProject();

        // Nama dan deskripsi backlog kini di halaman Pengaturan Project.
        $this->actingAs($owner)->get(route('projects.settings.show', $project))
            ->assertOk()->assertSee('Nama Sprint Backlog')->assertSee('Deskripsi Sprint Backlog');
        $this->actingAs($owner)->get('/projects/'.$project->id)
            ->assertOk()
            ->assertSee('Nama Sprint')->assertSee('<dialog', false)->assertSee('Buat Sprint', false)
            ->assertSee('data-sprint-dialog-open', false)->assertSee('bk-stats', false)
            ->assertDontSee('name="start_date"', false)->assertDontSee('name="end_date"', false);

        $this->actingAs($owner)->put('/projects/'.$project->id.'/backlog', [
            'name' => 'Sprint Backlog Produk',
            'description' => 'Daftar seluruh sprint dan kebutuhan project.',
        ])->assertRedirect(route('projects.settings.show', $project)); // kembali ke Pengaturan Project

        $backlog = Backlog::query()->sole();
        $this->assertSame($project->id, $backlog->project_id);
        $this->assertSame('Sprint Backlog Produk', $backlog->name);
        $this->assertSame('Daftar seluruh sprint dan kebutuhan project.', $backlog->description);

        $this->actingAs($owner)->get('/projects/'.$project->id)->assertOk()
            ->assertSee('Sprint Backlog')->assertDontSee('Product Backlog')->assertDontSee('Sprint Planning');
    }

    public function test_sprint_contains_complete_user_stories_with_unique_ids_and_created_at_dates(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProject();
        $backlog = $project->backlog;

        $this->actingAs($owner)->post('/projects/'.$project->id.'/sprints', ['name' => 'Sprint 1'])
            ->assertRedirect('/projects/'.$project->id);
        $sprint = Sprint::query()->sole();
        $this->assertSame($backlog->id, $sprint->backlog_id);
        $this->assertNull($sprint->start_date);
        $this->assertNull($sprint->end_date);
        $this->assertNull($sprint->goal);
        $this->assertNotNull($sprint->created_at);

        $actor = Actor::query()->where('name', 'Administrator')->sole();
        foreach (['Kelola unit kerja', 'Kelola akun belanja'] as $feature) {
            $this->actingAs($owner)->post('/sprints/'.$sprint->id.'/stories', [
                'title' => $feature,
                'actor_id' => $actor->id,
                'sprint_purpose' => 'Sistem mengenali data pengusul anggaran.',
                'acceptance_criteria' => 'Data dapat dibuat, diperbarui, dan dicari.',
            ])->assertRedirect('/projects/'.$project->id);
        }

        $this->assertSame(['US-01', 'US-02'], Story::query()->orderBy('id')->get()->map->display_id->all());
        $this->assertSame(2, $sprint->fresh()->stories()->count());
        $this->assertDatabaseHas('stories', ['sprint_id' => $sprint->id, 'actor_id' => $actor->id, 'title' => 'Kelola unit kerja']);
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
