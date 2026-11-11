<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Story;
use App\Models\User;
use App\Services\AgentConfiguration;
use App\Support\Chat\SprintChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

/** Chat Scrum Master dipisah per sprint; laporan SM masuk ke sprint-nya. */
class SprintChatTest extends TestCase
{
    use RefreshDatabase;

    private function mockStrictSm(): void
    {
        config(['ai.cliproxy.agents_enabled' => true, 'ai.cliproxy.api_key' => 'k', 'ai.cliproxy.base_url' => 'http://127.0.0.1:8317']);
        $this->mock(AgentConfiguration::class, function (MockInterface $mock) {
            $mock->shouldReceive('current')->with('scrum-master')->andReturn(['status' => 'verified', 'model' => 'gpt-5.6-sol']);
        });
        Http::fake([
            'http://127.0.0.1:8317/v1/models' => Http::response(['data' => [['id' => 'gpt-5.6-sol', 'owned_by' => 'openai']]]),
            'http://127.0.0.1:8317/v1/chat/completions' => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Siap.']]]]),
        ]);
    }

    private function sprints(): array
    {
        $project = Project::create(['name' => 'Toko Online', 'project_type' => 'web']);
        $one = Sprint::create(['project_id' => $project->id, 'name' => 'Sprint Satu', 'status' => 'ACTIVE', 'goal' => 'Katalog jalan', 'revision' => 1]);
        $two = Sprint::create(['project_id' => $project->id, 'name' => 'Sprint Dua', 'status' => 'PLANNING', 'revision' => 1]);

        return [$project, $one, $two];
    }

    public function test_each_sprint_gets_its_own_conversation_and_messages_do_not_mix(): void
    {
        $this->mockStrictSm();
        [, $one, $two] = $this->sprints();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/chat/messages', ['message' => 'Soal sprint satu', 'sprint_id' => $one->id])->assertOk();
        $this->actingAs($user)->postJson('/chat/messages', ['message' => 'Soal sprint dua', 'sprint_id' => $two->id])->assertOk();

        $this->assertSame(2, ChatConversation::query()->where('user_id', $user->id)->whereNotNull('sprint_id')->count());

        $this->actingAs($user)->get('/chat?sprint='.$one->id)->assertOk()
            ->assertSee('Soal sprint satu')->assertDontSee('Soal sprint dua')
            ->assertSee('Katalog jalan');
        $this->actingAs($user)->get('/chat?sprint='.$two->id)->assertOk()
            ->assertSee('Soal sprint dua')->assertDontSee('Soal sprint satu');
    }

    public function test_ai_receives_sprint_context_and_previous_messages(): void
    {
        $this->mockStrictSm();
        [, $one] = $this->sprints();
        Story::create(['project_id' => $one->project_id, 'sprint_id' => $one->id, 'title' => 'Login pelanggan', 'estimate_points' => 3]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/chat/messages', ['message' => 'Pesan pertama', 'sprint_id' => $one->id])->assertOk();
        $this->actingAs($user)->postJson('/chat/messages', ['message' => 'Pesan kedua', 'sprint_id' => $one->id])->assertOk();

        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), '/chat/completions')) {
                return false;
            }
            $messages = $request->data()['messages'];
            $last = end($messages)['content'] ?? '';

            return str_contains($last, 'Sprint Satu')
                && str_contains($last, 'Login pelanggan')
                && str_contains($last, 'PO: Pesan pertama')
                && str_contains($last, 'SM: Siap.')
                && str_contains($last, 'Pesan PO saat ini: Pesan kedua');
        });
    }

    public function test_reports_are_cards_in_the_sprint_thread_not_other_sprints(): void
    {
        [, $one, $two] = $this->sprints();
        $user = User::factory()->create();
        $story = Story::create(['project_id' => $one->project_id, 'sprint_id' => $one->id, 'title' => 'Katalog']);

        $conversation = SprintChat::forStory($user, $story, 'x');
        SprintChat::report($conversation, 'Hasil final: 5 poin', '/stories/'.$story->id.'/discussion', 'Lihat diskusi tim');

        $this->assertSame($one->id, $conversation->sprint_id);
        $this->assertDatabaseHas('chat_messages', ['kind' => 'report', 'link_label' => 'Lihat diskusi tim']);

        $this->actingAs($user)->get('/chat?sprint='.$one->id)->assertOk()
            ->assertSee('LAPORAN SCRUM MASTER')->assertSee('Hasil final: 5 poin')->assertSee('Lihat diskusi tim');
        $this->actingAs($user)->get('/chat?sprint='.$two->id)->assertOk()
            ->assertDontSee('Hasil final: 5 poin');
    }

    public function test_legacy_conversations_stay_in_a_read_only_archive(): void
    {
        $this->mockStrictSm();
        [, $one] = $this->sprints();
        $user = User::factory()->create();
        $old = ChatConversation::create(['user_id' => $user->id, 'uuid' => (string) Str::uuid(), 'title' => 'Obrolan lama', 'agent_profile' => 'scrum-master']);
        $old->messages()->create(['role' => 'user', 'content' => 'isi arsip lama']);

        $this->actingAs($user)->get('/chat?sprint='.$one->id)->assertOk()
            ->assertSee('Arsip chat lama')->assertDontSee('isi arsip lama');
        $this->actingAs($user)->get('/chat?arsip='.$old->uuid)->assertOk()
            ->assertSee('isi arsip lama')->assertSee('Arsip ini hanya bisa dibaca');
        $this->actingAs($user)->postJson('/chat/messages', ['message' => 'lanjut', 'sprint_id' => $one->id, 'conversation_id' => $old->uuid])
            ->assertStatus(422);
    }

    public function test_chat_defaults_to_the_active_sprint_and_sidebar_links_to_chat(): void
    {
        [, $one] = $this->sprints();
        $user = User::factory()->create();

        $this->actingAs($user)->get('/chat')->assertOk()->assertSee('Sprint Satu')->assertSee('Chat Scrum Master');
        $this->actingAs($user)->get('/projects')->assertOk()->assertSee('href="'.route('chat.index').'"', false);
    }

    public function test_activity_dock_is_always_present_and_points_to_the_sprint_chat(): void
    {
        $user = User::factory()->create();
        $html = $this->actingAs($user)->get('/projects')->assertOk()->getContent();

        $this->assertStringContainsString('id="activity-dock"', $html);
        $this->assertMatchesRegularExpression('/id="activity-dock"[^>]*data-chat-url="[^"]*chat"/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="activity-dock"[^>]*\shidden/', $html);
    }
}
