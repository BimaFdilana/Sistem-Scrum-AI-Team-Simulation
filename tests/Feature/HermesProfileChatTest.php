<?php

namespace Tests\Feature;

use App\Exceptions\ModelNotChosenException;
use App\Services\AgentConfiguration;
use App\Services\CliProxyApiClient;
use App\Services\HermesProfileChat;
use Illuminate\Support\Facades\File;
use Mockery\MockInterface;
use Tests\TestCase;

class HermesProfileChatTest extends TestCase
{
    private string $fakeBin;

    private string $argsFile;

    private string $answerFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeBin = storage_path('framework/testing/hermes-chat-fake-'.uniqid().'.sh');
        $this->argsFile = storage_path('framework/testing/hermes-chat-args-'.uniqid().'.txt');
        $this->answerFile = storage_path('framework/testing/hermes-chat-answer-'.uniqid().'.jsonl');
        // Biner hermes palsu: mencatat argumen, lalu menyalin isi file prompt
        // yang dirujuk --query-file, lalu menjawab jawaban gaya stream-json.
        //
        // PENTING: file prompt dibaca dengan cara mengikuti argumen
        // --query-file, BUKAN dengan pola path. Versi lama memakai pola
        // `/tmp/*`, padahal sys_get_temp_dir() di host ini menunjuk ke
        // ~/.hermes/cache/scratch — sehingga isi prompt tidak pernah ikut
        // tercatat dan assertion atas isi prompt selalu gagal walau kode
        // benar (kegagalan lama yang membingungkan).
        $script = "#!/bin/sh\n";
        $script .= "printf '%s\\n' \"\$@\" > ".escapeshellarg($this->argsFile)."\n";
        $script .= 'while [ $# -gt 1 ]; do if [ "$1" = "--query-file" ]; then cat "$2" >> '.escapeshellarg($this->argsFile)."; fi; shift; done\n";
        $script .= 'cat '.escapeshellarg($this->answerFile)."\n";
        file_put_contents($this->fakeBin, $script);
        // Jawaban gaya stream-json ditulis dari PHP (bukan di-escape di shell),
        // supaya kutip ganda di dalam event tidak perlu di-escape berlapis.
        file_put_contents($this->answerFile, json_encode([
            'type' => 'result',
            'session_id' => 'local-fake',
            'exit_code' => 0,
            'text' => '{"estimate_points":5,"rationale":"alasan uji"}',
        ])."\n");
        chmod($this->fakeBin, 0755);
        config(['ai.hermes.binary' => $this->fakeBin]);
        // Model menu, ketat seperti HermesTaskRunnerTest: peran sudah
        // memilih model terverifikasi yang ada di daftar gateway.
        $this->mock(CliProxyApiClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('models')->andReturn([
                ['id' => 'menu-model-be', 'owned_by' => 'test'],
            ])->byDefault();
        });
        $this->mock(AgentConfiguration::class, function (MockInterface $mock) {
            $mock->shouldReceive('current')->andReturn(['status' => 'verified', 'model' => 'menu-model-be'])->byDefault();
        });
    }

    protected function tearDown(): void
    {
        File::delete($this->fakeBin);
        File::delete($this->argsFile);
        File::delete($this->answerFile);
        parent::tearDown();
    }

    public function test_profile_name_mapping(): void
    {
        $this->assertSame('scrumagent', HermesProfileChat::profileNameForSlug('scrum-master'));
        $this->assertSame('backend-developer', HermesProfileChat::profileNameForSlug('backend-developer'));
        $this->assertSame('qa-engineer', HermesProfileChat::profileNameForSlug('qa-engineer'));
        $this->assertSame('tidak-dikenal', HermesProfileChat::profileNameForSlug('tidak-dikenal'));
    }

    public function test_chat_passes_profile_query_file_and_menu_model_and_returns_text(): void
    {
        $answer = app(HermesProfileChat::class)->chat('backend-developer', 'Vote: {"estimate_points":5}', 'ctx-1');

        $this->assertSame('{"estimate_points":5,"rationale":"alasan uji"}', $answer);
        $args = file_get_contents($this->argsFile) ?: '';
        $this->assertStringContainsString('backend-developer', $args);
        $this->assertStringContainsString('chat', $args);
        $this->assertStringContainsString('--query-file', $args);
        // Cara B: model dari menu, bukan model profile (glm-5.3).
        $this->assertStringContainsString('--model', $args);
        $this->assertStringContainsString('menu-model-be', $args);
        $this->assertStringNotContainsString('glm-5.3', $args);
        // Prompt yang dikirim harus memuat larangan mengubah file.
        $this->assertStringContainsString('jangan mengubah file apa pun', $args);
        // stream-json WAJIB: dengan --format text, keluaran adalah seluruh
        // transkrip sesi (echo prompt + banner + jawaban + ekor "Resume this
        // session"), dan extractJsonObject() lalu mengambil rentang yang
        // salah — akar 32 warning INVALID_JSON_SCHEMA di produksi.
        $this->assertStringContainsString('--format', $args);
        $this->assertStringContainsString('stream-json', $args);
        // Profile membawa provider-nya sendiri; kunci gateway tidak
        // boleh bocor ke argumen chat estimasi.
        $this->assertStringNotContainsString('--provider', $args);
        $this->assertStringNotContainsString('--api-key', $args);
    }

    public function test_chat_refuses_unselected_role_before_calling_hermes(): void
    {
        $this->mock(AgentConfiguration::class, function (MockInterface $mock) {
            $mock->shouldReceive('current')->andReturn(null);
        });

        try {
            app(HermesProfileChat::class)->chat('backend-developer', 'Vote', 'ctx-0');
            $this->fail('Seharusnya melempar ModelNotChosenException.');
        } catch (ModelNotChosenException $e) {
            $this->assertStringContainsString('belum dipilih di halaman AI Providers', $e->getMessage());
        }
        // Hermes tidak boleh dipanggil sama sekali untuk peran ini.
        $this->assertFileDoesNotExist($this->argsFile);
    }

    public function test_chat_maps_missing_profile_to_clear_error(): void
    {
        $script = "#!/bin/sh\n";
        $script .= "echo 'profile not found' >&2\n";
        $script .= "exit 1\n";
        file_put_contents($this->fakeBin, $script);

        $this->expectExceptionMessage('HERMES_PROFILE_NOT_FOUND');
        app(HermesProfileChat::class)->chat('backend-developer', 'Vote', 'ctx-2');
    }

    public function test_chat_rejects_empty_answer(): void
    {
        file_put_contents($this->fakeBin, "#!/bin/sh\nexit 0\n");

        $this->expectExceptionMessage('tidak mengembalikan jawaban');
        app(HermesProfileChat::class)->chat('qa-engineer', 'Vote', 'ctx-3');
    }
}
