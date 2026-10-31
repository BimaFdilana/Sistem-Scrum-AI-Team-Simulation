<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentProfilesTest extends TestCase
{
    use RefreshDatabase;

    public function test_p_o_is_redirected_to_scrum_master_and_cannot_open_specialist_chat(): void
    {
        Http::fake();
        $owner = User::factory()->create();

        $this->actingAs($owner)->get('/agents/backend-developer/chat')->assertRedirect('/chat');
        $this->actingAs($owner)->postJson('/agents/backend-developer/chat/messages', ['message' => 'Tinjau story ini.'])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_scrum_master_chat_remains_the_only_user_chat(): void
    {
        // Chat SM kini lewat gateway CLIProxyAPI dengan model SM dari
        // menu (aturan tanpa-default) — bukan lagi endpoint legacy.
        config([
            'ai.cliproxy.agents_enabled' => true,
            'ai.cliproxy.api_key' => 'test-key',
            'ai.cliproxy.base_url' => 'http://127.0.0.1:8317',
        ]);
        $this->mock(\App\Services\AgentConfiguration::class, function (\Mockery\MockInterface $mock) {
            $mock->shouldReceive('current')->with('scrum-master')->andReturn(['status' => 'verified', 'model' => 'sm-model']);
        });
        Http::fake([
            'http://127.0.0.1:8317/v1/models' => Http::response(['data' => [['id' => 'sm-model', 'owned_by' => 'openai']]]),
            'http://127.0.0.1:8317/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Saya Scrum Master.']]],
            ]),
        ]);
        $owner = User::factory()->create();

        $this->actingAs($owner)->get('/chat')->assertOk()->assertSee('Scrum Master');
        $this->actingAs($owner)->postJson('/chat/messages', ['message' => 'Bantu refinement story.'])
            ->assertOk()->assertJsonPath('assistant_message.content', 'Saya Scrum Master.');

        $this->assertDatabaseHas('chat_conversations', ['user_id' => $owner->id, 'agent_profile' => 'scrum-master']);
    }

    public function test_specialist_chat_endpoint_is_blocked_without_calling_an_agent(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->create())
            ->postJson('/agents/qa-engineer/chat/messages', ['message' => 'Tinjau story ini.'])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_unknown_agent_profile_is_not_callable(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->create())
            ->postJson('/agents/unknown-profile/chat/messages', ['message' => 'Halo'])
            ->assertNotFound();

        Http::assertNothingSent();
    }

    private function configureProfile(string $slug, string $baseUrl, string $apiKey): void
    {
        config([
            'services.agent_profiles.'.$slug.'.base_url' => $baseUrl,
            'services.agent_profiles.'.$slug.'.api_key' => $apiKey,
            'services.agent_profiles.'.$slug.'.model' => $slug,
        ]);
    }
}
