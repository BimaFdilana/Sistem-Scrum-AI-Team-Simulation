<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SprintRenameAndBoardLimitTest extends TestCase
{
    use RefreshDatabase;

    private function sprint(string $status = 'PLANNING'): Sprint
    {
        $project = Project::create(['name' => 'Toko', 'project_type' => 'web']);

        $backlog = $project->backlog()->firstOrCreate([], ['name' => 'Toko Backlog']);

        return Sprint::create(['backlog_id' => $backlog->id, 'project_id' => $project->id, 'name' => 'Sprint Lama', 'status' => $status, 'revision' => 1]);
    }

    public function test_sprint_name_can_be_renamed_in_any_status(): void
    {
        $user = User::factory()->create();
        foreach (['PLANNING', 'ACTIVE', 'COMPLETED'] as $status) {
            $sprint = $this->sprint($status);
            $this->actingAs($user)->patch(route('sprints.update', $sprint), ['name' => '  Sprint Baru '.$status.'  '])
                ->assertRedirect()->assertSessionHas('status');
            $this->assertSame('Sprint Baru '.$status, $sprint->fresh()->name);
        }
    }

    public function test_rename_validates_and_updates_chat_title(): void
    {
        $user = User::factory()->create();
        $sprint = $this->sprint();
        ChatConversation::create(['user_id' => $user->id, 'uuid' => (string) \Illuminate\Support\Str::uuid(), 'title' => 'Sprint Lama', 'agent_profile' => 'scrum-master', 'sprint_id' => $sprint->id]);

        $this->actingAs($user)->patch(route('sprints.update', $sprint), ['name' => ''])->assertSessionHasErrors('name');
        $this->actingAs($user)->patch(route('sprints.update', $sprint), ['name' => str_repeat('a', 121)])->assertSessionHasErrors('name');
        $this->assertSame('Sprint Lama', $sprint->fresh()->name);

        $this->actingAs($user)->patch(route('sprints.update', $sprint), ['name' => 'Nama Final']);
        $this->assertDatabaseHas('chat_conversations', ['sprint_id' => $sprint->id, 'title' => 'Nama Final']);
    }

    public function test_guest_cannot_rename(): void
    {
        $this->patch(route('sprints.update', $this->sprint()), ['name' => 'x'])->assertRedirect(route('login'));
    }

    public function test_project_page_offers_rename_and_chat_buttons(): void
    {
        $user = User::factory()->create();
        $sprint = $this->sprint();

        $this->actingAs($user)->get(route('projects.show', $sprint->project_id))->assertOk()
            ->assertSee('Ubah nama sprint')->assertSee(route('sprints.update', $sprint), false)
            ->assertSee('Chat dengan Scrum Master');
    }

    public function test_dashboard_board_shows_only_three_stories_per_column(): void
    {
        $user = User::factory()->create();
        $sprint = $this->sprint('ACTIVE');
        foreach (range(1, 5) as $i) {
            Story::create(['project_id' => $sprint->project_id, 'sprint_id' => $sprint->id, 'title' => 'Story nomor '.$i]);
        }

        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('Tampilkan 2 lagi')->getContent();
        // 5 story backlog: 3 terlihat + 2 di dalam <details>.
        $this->assertSame(5, substr_count($html, 'class="board-card '));
        $visible = explode('<details class="board-more">', $html)[0];
        $this->assertSame(3, substr_count($visible, 'Story nomor'));
    }
}
