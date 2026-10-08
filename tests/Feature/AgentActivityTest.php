<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_cards_route_po_chat_through_scrum_master_and_keep_activity_links(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->get('/agents')->assertOk();
        $response->assertSee('href="/chat"', false)->assertSee('Chat dengan Scrum Master');

        foreach (['backend-developer', 'frontend-developer', 'qa-engineer'] as $slug) {
            $response->assertDontSee('href="/agents/'.$slug.'/chat"', false)
                ->assertSee('Dipanggil melalui Scrum Master');
        }

        foreach (['scrum-master', 'backend-developer', 'frontend-developer', 'qa-engineer'] as $slug) {
            $response->assertSee('href="/agents/'.$slug.'/activity"', false)
                ->assertSee('Log activity');
        }
    }

    public function test_activity_page_shows_only_the_agents_tasks_with_date_and_time(): void
    {
        $owner = User::factory()->create();
        $project = Project::query()->create([
            'name' => 'Demo project',
            'project_type' => 'NEW_PROJECT',
            'stack' => 'Laravel',
            'status' => 'SETUP_REQUIRED',
        ]);
        $story = $project->stories()->create([
            'title' => 'Secure access',
            'description' => 'Keep workspace access safe.',
            'acceptance_criteria' => 'Guests cannot view protected pages.',
            'priority' => 1,
            'scope_status' => 'NEEDS_REFINEMENT',
            'revision' => 1,
        ]);
        $timestamp = Carbon::create(2026, 10, 7, 9, 5, 0);

        $backendTask = $story->tasks()->create([
            'created_by' => $owner->id,
            'title' => 'Implement login throttling',
            'description' => 'Limit repeated failed authentication attempts.',
            'assignee_profile' => 'backend-developer',
            'status' => 'BACKLOG',
            'story_revision' => 1,
            'revision' => 1,
        ]);
        $backendTask->forceFill(['created_at' => $timestamp, 'updated_at' => $timestamp])->save();

        $frontendTask = $story->tasks()->create([
            'created_by' => $owner->id,
            'title' => 'Build login form',
            'description' => 'Show validation feedback.',
            'assignee_profile' => 'frontend-developer',
            'status' => 'BACKLOG',
            'story_revision' => 1,
            'revision' => 1,
        ]);
        $frontendTask->forceFill(['created_at' => $timestamp->copy()->subDay(), 'updated_at' => $timestamp->copy()->subDay()])->save();

        $this->actingAs($owner)
            ->get('/agents/backend-developer/activity')
            ->assertOk()
            ->assertSee('Isi log (task)')
            ->assertSee('Tanggal')
            ->assertSee('Jam')
            ->assertSee('Implement login throttling')
            ->assertSee('07/10/2026')
            ->assertSee('09:05')
            ->assertDontSee('Build login form')
            ->assertSee('Catatan task tidak berarti task telah dieksekusi');

        $this->actingAs($owner)
            ->get('/agents/qa-engineer/activity')
            ->assertOk()
            ->assertSee('Belum ada aktivitas task yang tercatat');
    }

    public function test_unknown_agent_activity_page_returns_not_found(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/agents/not-an-agent/activity')
            ->assertNotFound();
    }
}
