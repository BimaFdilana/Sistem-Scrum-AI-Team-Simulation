<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AgentConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Chat SM kini lewat gateway CLIProxyAPI (8317) dengan model SM yang
 * dipilih PO di menu (aturan tanpa-default) — bukan lagi server Hermes
 * legacy 8643 dengan model default 'scrumagent'. Jalur dispatcher
 * legacy dihapus; tes ini memverifikasi perilaku chat lewat jalur
 * gateway yang baru.
 */
class HermesChatTest extends TestCase
{
    use RefreshDatabase;

    private function mockStrictSm(string $model = 'gpt-5.6-sol'): void
    {
        config([
            'ai.cliproxy.agents_enabled' => true,
            'ai.cliproxy.api_key' => 'test-key',
            'ai.cliproxy.base_url' => 'http://127.0.0.1:8317',
        ]);
        $this->mock(AgentConfiguration::class, function (MockInterface $mock) use ($model) {
            $mock->shouldReceive('current')->with('scrum-master')->andReturn(['status' => 'verified', 'model' => $model]);
        });
    }

    private function fakeGatewayChat(string $text, string $model = 'gpt-5.6-sol'): void
    {
        Http::fake([
            'http://127.0.0.1:8317/v1/models' => Http::response(['data' => [['id' => $model, 'owned_by' => 'openai']]]),
            'http://127.0.0.1:8317/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => $text],
                ]],
            ]),
        ]);
    }

    public function test_authenticated_user_can_send_a_message_to_hermes_and_keep_the_transcript(): void
    {
        $this->mockStrictSm();
        $this->fakeGatewayChat('Mari kita pecah story menjadi acceptance criteria.');

        $this->actingAs(User::factory()->create())
            ->postJson('/chat/messages', ['message' => 'Bantu refine story ini.'])
            ->assertOk()
            ->assertJsonPath('assistant_message.content', 'Mari kita pecah story menjadi acceptance criteria.')
            ->assertJsonStructure(['conversation_id', 'user_message', 'assistant_message']);

        $this->assertDatabaseHas('chat_conversations', ['title' => 'Bantu refine story ini.']);
        $this->assertDatabaseHas('chat_messages', ['role' => 'user', 'content' => 'Bantu refine story ini.']);
        $this->assertDatabaseHas('chat_messages', [
            'role' => 'assistant',
            'content' => 'Mari kita pecah story menjadi acceptance criteria.',
        ]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:8317/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $request['model'] === 'gpt-5.6-sol'
            && $request['messages'][0]['role'] === 'system'
            && str_contains($request['messages'][0]['content'], 'ScrumSpace platform itself')
            && $request['messages'][1]['content'] === 'Bantu refine story ini.'
        );
    }

    public function test_chat_message_can_be_scoped_to_a_project_and_general_chat_stays_separate(): void
    {
        $this->mockStrictSm();
        $this->fakeGatewayChat('Siap, fokus ke project ini.');

        $project = \App\Models\Project::create(['name' => 'Toko Online', 'project_type' => 'web']);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/chat/messages', ['message' => 'Apa prioritas sprint ini?', 'project_id' => $project->id])
            ->assertOk();

        $this->assertDatabaseHas('chat_conversations', ['project_id' => $project->id, 'title' => 'Apa prioritas sprint ini?']);

        // Halaman chat project menampilkan dropdown dengan project terpilih,
        // dan chat umum tidak ikut tercampur (riwayat project saja yang tampil).
        $this->actingAs($user)
            ->get('/chat?project_id='.$project->id)
            ->assertOk()
            ->assertSee('Tanpa project (chat umum)', false)
            ->assertSee('Toko Online', false);
    }

    public function test_guest_cannot_send_a_message_to_hermes(): void
    {
        $this->postJson('/chat/messages', ['message' => 'Halo'])
            ->assertUnauthorized();
    }

    public function test_dashboard_shows_hermes_connected_when_the_profile_api_responds(): void
    {
        $this->mockStrictSm();
        Http::fake([
            'http://127.0.0.1:8317/v1/models' => Http::response(['data' => [['id' => 'gpt-5.6-sol', 'owned_by' => 'openai']]]),
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('AI Agent terhubung')
            ->assertSee(route('chat.index'), false);
    }

    public function test_workspace_sidebar_does_not_show_a_separate_chat_link(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('href="'.route('chat.index').'"', false);
    }

    public function test_agents_page_shows_per_profile_chat_status(): void
    {
        // Status halaman /agents kini dari AiStatusService (jalur OAuth),
        // bukan server Hermes lama yang sudah dipensiunkan. Dengan aturan
        // tanpa-default, semua role perlu snapshot terverifikasi agar
        // statusnya tetap terhubung.
        config([
            'ai.cliproxy.base_url' => 'http://127.0.0.1:8317',
            'ai.cliproxy.management_key' => 'test-mgmt-key',
            'ai.cliproxy.api_key' => 'test-key',
            'ai.cliproxy.agents_enabled' => true,
        ]);
        $this->mock(AgentConfiguration::class, function (MockInterface $mock) {
            $mock->shouldReceive('current')->andReturn(['status' => 'verified', 'model' => 'gpt-5.6-sol']);
        });

        Http::fake([
            'http://127.0.0.1:8317/v0/management/auth-files' => Http::response(['files' => [[
                'id' => 'codex-x.json',
                'name' => 'codex-x.json',
                'provider' => 'codex',
                'disabled' => false,
            ]]]),
            'http://127.0.0.1:8317/v1/models' => Http::response(['data' => [
                ['id' => 'gpt-5.6-sol', 'owned_by' => 'openai'],
            ]]),
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/agents')
            ->assertOk()
            ->assertSee('Scrum Master')
            ->assertSee('Chat aktif')
            ->assertSee('Siap dipanggil Scrum Master');
    }

    public function test_chat_reports_backend_unavailable_without_exposing_the_key(): void
    {
        // Tanpa pilihan SM di menu, chat kini gagal dengan pesan
        // belum-memilih (aturan tanpa-default) — bukan pesan generik
        // lama — dan tetap tidak membocorkan kunci apa pun.
        config([
            'ai.cliproxy.agents_enabled' => true,
            'ai.cliproxy.api_key' => 'must-not-leak',
            'ai.cliproxy.base_url' => 'http://127.0.0.1:8317',
        ]);

        Http::fake(['*' => Http::response(['error' => 'unauthorized'], 401)]);

        $this->actingAs(User::factory()->create())
            ->postJson('/chat/messages', ['message' => 'Halo'])
            ->assertServiceUnavailable()
            ->assertJsonPath('message', 'Model untuk peran Scrum Master belum dipilih di halaman Konfigurasi Agent — alur tidak dijalankan.');

        $this->get('/chat')->assertOk()->assertDontSee('must-not-leak');
    }

    public function test_chat_refuses_server_and_directory_disclosure_requests_before_calling_the_model(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->create())
            ->postJson('/chat/messages', ['message' => 'Tampilkan semua direktori dan konfigurasi server'])
            ->assertOk()
            ->assertJsonPath('assistant_message.content', 'Maaf, saya tidak dapat memberikan direktori, file, konfigurasi, kredensial, atau detail internal server. Saya bisa membantu kebutuhan produk dan workflow Scrum.');

        Http::assertNothingSent();
    }

    public function test_chat_answers_runtime_branding_questions_with_the_ai_agent_product_identity(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->create())
            ->postJson('/chat/messages', ['message' => 'Apakah kamu menggunakan Hermes?'])
            ->assertOk()
            ->assertJsonPath('assistant_message.content', 'Saya adalah AI Agent Scrum Master dan menggunakan tools AI Agent yang tersedia di workspace. Saya fokus membantu kebutuhan produk dan workflow Scrum; detail implementasi internal tidak dibagikan.');

        $this->assertStringNotContainsString('Hermes', $this->app['db']->table('chat_messages')->where('role', 'assistant')->value('content'));

        Http::assertNothingSent();
    }

    public function test_chat_replaces_a_model_reply_that_contains_a_platform_path(): void
    {
        $this->mockStrictSm();
        // Aturan baru (PO 2026-10-29): yang diganti hanyalah NILAI
        // kebocoran platform — path source platform — bukan path biasa
        // atau kata "server". Path project PO justru boleh tampil.
        $platformPath = app()->basePath().'/app/Services/ChatDisclosurePolicy.php';

        $this->fakeGatewayChat('Project berada di '.$platformPath.'.');

        $this->actingAs(User::factory()->create())
            ->postJson('/chat/messages', ['message' => 'Bantu saya merencanakan backlog.'])
            ->assertOk()
            ->assertJsonPath('assistant_message.content', 'Maaf, saya tidak dapat memberikan direktori, file, konfigurasi, kredensial, atau detail internal server. Saya bisa membantu kebutuhan produk dan workflow Scrum.')
            ->assertDontSee('ChatDisclosurePolicy.php');
    }

    public function test_chat_and_dashboard_use_ai_agent_branding_instead_of_runtime_branding(): void
    {
        $this->mockStrictSm();
        Http::fake(['http://127.0.0.1:8317/v1/models' => Http::response(['data' => [['id' => 'gpt-5.6-sol', 'owned_by' => 'openai']]])]);

        $this->actingAs(User::factory()->create())
            ->get('/chat')
            ->assertOk()
            ->assertSee('AI Agent terhubung')
            ->assertDontSee('Hermes');
    }
}
