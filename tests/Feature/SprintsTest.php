<?php

namespace Tests\Feature;

use App\Models\Actor;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SprintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_owner_can_create_sprints_without_goal_or_date_inputs(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProject();

        $this->actingAs($owner)->followingRedirects()->get('/projects/'.$project->id.'/sprints/create')
            ->assertOk()->assertSee('Nama Sprint')->assertSee('<dialog', false)
            ->assertSee('data-sprint-dialog-open', false)->assertDontSee('Tujuan sprint')
            ->assertDontSee('start_date')->assertDontSee('end_date');

        $this->actingAs($owner)->post('/projects/'.$project->id.'/sprints', ['name' => 'Sprint 1'])
            ->assertRedirect('/projects/'.$project->id.'/sprint-backlog');
        $sprint = Sprint::query()->sole();
        $this->assertSame('Sprint 1', $sprint->name);
        $this->assertNull($sprint->goal);
        $this->assertSame('PENDING', $sprint->goal_source);
        $this->assertNull($sprint->start_date);
        $this->assertNull($sprint->end_date);
        $this->assertNotNull($sprint->created_at);
    }

    public function test_sprint_name_validation_returns_to_backlog_with_modal_reopened(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProject();

        $this->actingAs($owner)->from('/projects/'.$project->id.'/sprint-backlog')
            ->followingRedirects()->post('/projects/'.$project->id.'/sprints', ['form_context' => 'sprint'])
            ->assertOk()->assertSee('data-open-on-load="true"', false)
            ->assertSee('Nama sprint wajib diisi.');

        $this->assertSame(0, Sprint::query()->count());
    }

    public function test_sprint_goal_is_generated_from_its_user_stories_by_scrum_master_ai(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'Mengelola data penganggaran secara konsisten dan tervalidasi.']]]]),
            'http://127.0.0.1:8317/v1/models' => Http::response(['data' => [['id' => 'sm-model', 'owned_by' => 'openai']]]),
        ]);
        // Sprint goal SM kini ketat: perlu pilihan SM di menu.
        config(['ai.cliproxy.agents_enabled' => true, 'ai.cliproxy.api_key' => 'test-key', 'ai.cliproxy.base_url' => 'http://127.0.0.1:8317']);
        $this->mock(\App\Services\AgentConfiguration::class, function (\Mockery\MockInterface $mock) {
            $mock->shouldReceive('current')->with('scrum-master')->andReturn(['status' => 'verified', 'model' => 'sm-model']);
        });
        $owner = User::factory()->create();
        $project = $this->createProject();
        $sprint = $this->createSprint($project);
        $actor = Actor::query()->where('name', 'Administrator')->sole();
        $project->stories()->create([
            'sprint_id' => $sprint->id, 'actor_id' => $actor->id, 'user_story_key' => 'US-01',
            'title' => 'Mengelola data unit kerja', 'sprint_purpose' => 'Memastikan hierarki pengusul dikenali.',
            'acceptance_criteria' => 'Kode unit kerja harus unik dan dapat dicari.', 'revision' => 1,
        ]);

        $this->actingAs($owner)->from('/sprints/'.$sprint->id)
            ->post('/sprints/'.$sprint->id.'/generate-goal')
            ->assertRedirect('/sprints/'.$sprint->id)->assertSessionHas('status');

        $this->assertSame('Mengelola data penganggaran secara konsisten dan tervalidasi.', $sprint->fresh()->goal);
        $this->assertSame('AI_DRAFT', $sprint->fresh()->goal_source);
        Http::assertSent(fn ($request): bool => isset($request['messages'][1]['content'])
            && str_contains((string) $request['messages'][1]['content'], 'US-01')
            && str_contains((string) $request['messages'][1]['content'], 'Acceptance Criteria'));
    }

    public function test_scrum_master_goal_generation_fails_safely_when_ai_is_unavailable(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([], 503),
            'http://127.0.0.1:8317/v1/models' => Http::response(['data' => [['id' => 'sm-model', 'owned_by' => 'openai']]]),
        ]);
        config(['ai.cliproxy.agents_enabled' => true, 'ai.cliproxy.api_key' => 'test-key', 'ai.cliproxy.base_url' => 'http://127.0.0.1:8317']);
        $this->mock(\App\Services\AgentConfiguration::class, function (\Mockery\MockInterface $mock) {
            $mock->shouldReceive('current')->with('scrum-master')->andReturn(['status' => 'verified', 'model' => 'sm-model']);
        });
        $owner = User::factory()->create();
        $project = $this->createProject();
        $sprint = $this->createSprint($project);
        $project->stories()->create(['sprint_id' => $sprint->id, 'title' => 'A feature', 'sprint_purpose' => 'A purpose.', 'acceptance_criteria' => 'A clear acceptance criterion.']);

        $this->actingAs($owner)->from('/sprints/'.$sprint->id)
            ->post('/sprints/'.$sprint->id.'/generate-goal')
            ->assertRedirect('/sprints/'.$sprint->id)->assertSessionHasErrors('goal');
        $this->assertNull($sprint->fresh()->goal);
    }

    public function test_po_must_approve_the_generated_sprint_goal_and_complete_story_scope_before_starting(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'Menyelesaikan akses aman untuk pengguna.']]]]),
            'http://127.0.0.1:8317/v1/models' => Http::response(['data' => [['id' => 'sm-model', 'owned_by' => 'openai']]]),
        ]);
        config(['ai.cliproxy.agents_enabled' => true, 'ai.cliproxy.api_key' => 'test-key', 'ai.cliproxy.base_url' => 'http://127.0.0.1:8317']);
        $this->mock(\App\Services\AgentConfiguration::class, function (\Mockery\MockInterface $mock) {
            $mock->shouldReceive('current')->with('scrum-master')->andReturn(['status' => 'verified', 'model' => 'sm-model']);
        });
        $owner = User::factory()->create();
        $project = $this->createProject();
        $sprint = $this->createSprint($project);
        $actor = Actor::query()->where('name', 'Administrator')->sole();
        $story = $project->stories()->create([
            'sprint_id' => $sprint->id, 'actor_id' => $actor->id, 'user_story_key' => 'US-01',
            'title' => 'Masuk dengan aman', 'sprint_purpose' => 'Pengguna dapat mengakses fitur sesuai kewenangan.',
            'acceptance_criteria' => 'Pengguna yang belum login diarahkan ke halaman login.', 'revision' => 1,
        ]);

        $this->actingAs($owner)->from('/sprints/'.$sprint->id)->post('/sprints/'.$sprint->id.'/generate-goal');
        $this->actingAs($owner)->post('/sprints/'.$sprint->id.'/approve-scope')->assertRedirect('/sprints/'.$sprint->id);
        $this->assertSame('APPROVED', $story->fresh()->scope_status);
        $this->assertNotNull($sprint->fresh()->scope_approved_revision);

        $this->actingAs($owner)->post('/sprints/'.$sprint->id.'/start')->assertRedirect('/sprints/'.$sprint->id);
        $this->assertSame('ACTIVE', $sprint->fresh()->status);
    }

    public function test_legacy_sprints_index_redirects_to_the_unified_sprint_backlog_page(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProject();
        $this->actingAs($owner)->get('/projects/'.$project->id.'/sprints')
            ->assertRedirect('/projects/'.$project->id.'/sprint-backlog');
    }

    public function test_sprint_detail_page_renders_approval_errors_without_a_blade_parse_error(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProject();
        $sprint = $this->createSprint($project);

        $this->actingAs($owner)->get('/sprints/'.$sprint->id)
            ->assertOk()
            ->assertSee('Tujuan Sprint')
            ->assertSee('Stories dalam '.$sprint->name);
    }

    private function createSprint(Project $project): Sprint
    {
        return $project->sprints()->create([
            'backlog_id' => $project->backlog->id,
            'name' => 'Sprint 1',
            'status' => 'PLANNING',
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
