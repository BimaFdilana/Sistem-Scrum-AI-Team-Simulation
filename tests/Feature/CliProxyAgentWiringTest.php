<?php

namespace Tests\Feature;

use App\Services\AgentProfileClient;
use App\Services\CliProxyApiClient;
use App\Services\HermesClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CliProxyAgentWiringTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.cliproxy.agents_enabled' => true]);
        Cache::forget('cliproxy:models');
    }

    protected function fakeGateway(array $models): void
    {
        Cache::forget('cliproxy:models');
        Http::fake([
            'http://127.0.0.1:8317/v1/models' => Http::response(['data' => $models]),
            'http://127.0.0.1:8317/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Jawaban AI']]],
            ]),
        ]);
    }

    public function test_strict_resolver_rejects_unselected_role_without_calling_model(): void
    {
        // Aturan tanpa-default menggantikan modelForRole/smartestModel:
        // tanpa snapshot menu terverifikasi, role tidak tersedia dan
        // sendMessage melempar peringatan TANPA panggilan chat apa pun.
        $this->fakeGateway([
            ['id' => 'gpt-5.6-luna', 'owned_by' => 'openai'],
            ['id' => 'claude-opus-4-6', 'owned_by' => 'anthropic'],
            ['id' => 'gemini-2.5-pro', 'owned_by' => 'google'],
        ]);

        $profiles = app(AgentProfileClient::class);

        $this->assertFalse($profiles->isAvailable('qa-engineer'));
        $this->assertFalse(app(HermesClient::class)->isAvailable());

        try {
            $profiles->sendMessage('qa-engineer', 'halo', 'conv-1');
            $this->fail('Seharusnya melempar ModelNotChosenException.');
        } catch (\App\Exceptions\ModelNotChosenException $e) {
            $this->assertStringContainsString('belum dipilih di halaman Konfigurasi Agent', $e->getMessage());
        }

        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/v1/chat/completions'));
    }

    public function test_agents_disabled_means_unavailable(): void
    {
        config(['ai.cliproxy.agents_enabled' => false]);

        $client = app(CliProxyApiClient::class);

        $this->assertFalse(app(AgentProfileClient::class)->isAvailable('qa-engineer'));
        $this->assertFalse(app(HermesClient::class)->isAvailable());
        $this->assertNull($client->chat('gpt-5.6-luna', 'sys', 'hi'));
    }

    public function test_agen_terhubung_dan_bisa_chat_lewat_cliproxy(): void
    {
        $this->fakeGateway([
            ['id' => 'gpt-5.6-luna', 'owned_by' => 'openai'],
            ['id' => 'claude-opus-4-6', 'owned_by' => 'anthropic'],
            ['id' => 'gemini-2.5-pro', 'owned_by' => 'google'],
        ]);
        // Chat kini ketat: perlu snapshot menu terverifikasi per role.
        $this->mock(\App\Services\AgentConfiguration::class, function (\Mockery\MockInterface $mock) {
            $mock->shouldReceive('current')->with('qa-engineer')->andReturn(['status' => 'verified', 'model' => 'claude-opus-4-6']);
            $mock->shouldReceive('current')->with('scrum-master')->andReturn(['status' => 'verified', 'model' => 'gemini-2.5-pro']);
        });

        $profiles = app(AgentProfileClient::class);

        $this->assertTrue($profiles->isAvailable('qa-engineer'));
        $this->assertSame('Jawaban AI', $profiles->sendMessage('qa-engineer', 'halo', 'conv-1'));

        $this->assertTrue(app(HermesClient::class)->isAvailable());
        $this->assertSame('Jawaban AI', app(HermesClient::class)->sendMessage('halo', 'conv-2'));

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/chat/completions')
            && $request['model'] === 'claude-opus-4-6');
    }

    public function test_katalog_model_dikelompokkan_per_provider_oauth(): void
    {
        $this->fakeGateway([
            ['id' => 'gpt-5.6-luna', 'owned_by' => 'openai'],
            ['id' => 'claude-opus-4-6', 'owned_by' => 'anthropic'],
            ['id' => 'gemini-2.5-pro', 'owned_by' => 'google'],
        ]);

        $grouped = app(CliProxyApiClient::class)->modelsByProvider();

        $this->assertSame(['gpt-5.6-luna'], $grouped['codex']);
        $this->assertSame(['claude-opus-4-6'], $grouped['anthropic']);
        $this->assertSame(['gemini-2.5-pro'], $grouped['google']);

        $catalog = app(\App\Services\AgentModelCatalog::class)->fetch('qa-engineer');

        $this->assertSame('online', $catalog['status']);
        $this->assertTrue(collect($catalog['providers'])->firstWhere('slug', 'anthropic')['available']);
        $this->assertContains('claude-opus-4-6', collect($catalog['providers'])->firstWhere('slug', 'anthropic')['models']);
    }

    public function test_chat_mengirim_blok_vision_bila_ada_gambar(): void
    {
        $this->fakeGateway([
            ['id' => 'gpt-5.6-luna', 'owned_by' => 'openai'],
        ]);

        $content = app(CliProxyApiClient::class)->chat(
            'gpt-5.6-luna', 'sys', 'Apa isi gambar ini?',
            120, ['data' => base64_encode('fake-bytes'), 'mime' => 'image/png']
        );

        $this->assertSame('Jawaban AI', $content);
        Http::assertSent(function ($request) {
            // chatResilient juga mengambil katalog model (untuk pesan MODEL_PROBLEMATIC);
            // assertion ini hanya untuk request chat completions.
            if (! str_ends_with($request->url(), '/v1/chat/completions')) {
                return false;
            }
            $userContent = $request['messages'][1]['content'];

            return is_array($userContent)
                && $userContent[0] === ['type' => 'text', 'text' => 'Apa isi gambar ini?']
                && $userContent[1]['type'] === 'image_url'
                && str_starts_with($userContent[1]['image_url']['url'], 'data:image/png;base64,');
        });
    }

    public function test_chat_dengan_gambar_diteruskan_lalu_dibuang(): void
    {
        $this->fakeGateway([
            ['id' => 'gpt-5.6-luna', 'owned_by' => 'openai'],
        ]);
        // Chat SM kini ketat: perlu snapshot SM terverifikasi.
        $this->mock(\App\Services\AgentConfiguration::class, function (\Mockery\MockInterface $mock) {
            $mock->shouldReceive('current')->with('scrum-master')->andReturn(['status' => 'verified', 'model' => 'gpt-5.6-luna']);
        });

        $image = UploadedFile::fake()->image('mockup.png', 100, 100)->size(100);

        $this->actingAs(User::factory()->create())
            ->post('/chat/messages', ['message' => 'Tolong review mockup ini.', 'image' => $image])
            ->assertOk()
            ->assertJsonPath('assistant_message.content', 'Jawaban AI');

        // Gambar tidak disimpan; hanya penanda di pesan.
        $this->assertDatabaseHas('chat_messages', [
            'role' => 'user',
            'content' => "Tolong review mockup ini.\n[lampiran gambar]",
        ]);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/chat/completions')
            && is_array($request['messages'][1]['content'])
            && $request['messages'][1]['content'][1]['type'] === 'image_url');
    }

    public function test_chat_menolak_gambar_lebih_dari_5mb(): void
    {
        $this->fakeGateway([
            ['id' => 'gpt-5.6-luna', 'owned_by' => 'openai'],
        ]);

        $image = UploadedFile::fake()->image('besar.png')->size(6000);

        $this->actingAs(User::factory()->create())
            ->post('/chat/messages', ['message' => 'Lihat ini.', 'image' => $image])
            ->assertRedirect()
            ->assertSessionHasErrors('image');
    }
}
