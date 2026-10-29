<?php

namespace Tests\Feature;

use App\Services\AgentProfileClient;
use App\Services\ChatDisclosurePolicy;
use App\Services\CliProxyApiClient;
use Mockery;
use Tests\TestCase;

class AgentProfileClientDisclosureTest extends TestCase
{
    private function mockCliproxy(string $text, ?callable $captureSystemPrompt = null): void
    {
        $cliproxy = Mockery::mock(CliProxyApiClient::class);
        $cliproxy->shouldReceive('resolveModel')->with('qa-engineer')->andReturn('gemini-test');
        $cliproxy->shouldReceive('chatResilient')->withArgs(function ($model, $systemPrompt, $message) use ($captureSystemPrompt) {
            if ($captureSystemPrompt !== null) {
                $captureSystemPrompt($systemPrompt);
            }

            return true;
        })->andReturn(['text' => $text, 'error' => null, 'model' => 'gemini-test']);
        $this->app->instance(CliProxyApiClient::class, $cliproxy);
    }

    public function test_qa_words_no_longer_replace_structured_vote(): void
    {
        // Aturan baru (PO 2026-10-29): kata "server"/"credentials" adalah
        // kosakata sah pembahasan project — vote TIDAK boleh lagi diganti
        // kalimat penolakan (kasus QA US-01 Run #1–#6).
        $vote = '{"estimate_points":5,"rationale":"Perlu uji di server dan cek credentials flow."}';
        $this->mockCliproxy($vote);
        $result = app(AgentProfileClient::class)->sendMessage('qa-engineer', 'Vote.', 'test-1');
        $this->assertSame($vote, $result);
    }

    public function test_structured_vote_bypasses_chat_filter_when_flag_is_false(): void
    {
        $vote = '{"estimate_points":5,"rationale":"Perlu uji di server dan cek credentials flow."}';
        $this->mockCliproxy($vote);
        $result = app(AgentProfileClient::class)->sendMessage('qa-engineer', 'Vote.', 'test-2', false);
        $this->assertSame($vote, $result);
    }

    public function test_platform_path_in_answer_is_still_replaced_by_default_filter(): void
    {
        $leak = 'Lihat '.app()->basePath().'/app/Services/ChatDisclosurePolicy.php';
        $this->mockCliproxy($leak);
        $result = app(AgentProfileClient::class)->sendMessage('qa-engineer', 'Vote.', 'test-3');
        $this->assertSame(ChatDisclosurePolicy::SAFE_RESPONSE, $result);

        $this->mockCliproxy($leak);
        $result = app(AgentProfileClient::class)->sendMessage('qa-engineer', 'Vote.', 'test-4', false);
        $this->assertSame($leak, $result);
    }

    public function test_execution_mode_prompt_is_json_only_without_consultation_line(): void
    {
        // Regresi eksekusi US-01 #4: system prompt "consultation and
        // analysis only" bertabrakan dengan instruksi patch JSON, agent
        // Frontend dua kali menjawab prosa alih-alih patch.
        $captured = null;
        $this->mockCliproxy('{"files":[],"summary":"Ok."}', function ($systemPrompt) use (&$captured) {
            $captured = $systemPrompt;
        });
        app(AgentProfileClient::class)->sendMessage('qa-engineer', 'Kerjakan task.', 'test-exec', false, true);

        $this->assertNotNull($captured);
        $this->assertStringContainsString('ONLY the JSON object', $captured);
        $this->assertStringNotContainsString('consultation and analysis only', $captured);
        $this->assertStringNotContainsString('Kepribadian profesionalmu', $captured);
        // Aturan platform-vs-project tetap berlaku dalam mode eksekusi.
        $this->assertStringContainsString('TARGET PROJECT', $captured);
        $this->assertStringContainsString('ScrumSpace platform itself', $captured);
    }

    public function test_consultation_mode_prompt_keeps_consultation_line(): void
    {
        $captured = null;
        $this->mockCliproxy('{"estimate_points":5,"rationale":"Ok."}', function ($systemPrompt) use (&$captured) {
            $captured = $systemPrompt;
        });
        app(AgentProfileClient::class)->sendMessage('qa-engineer', 'Vote.', 'test-consult');

        $this->assertStringContainsString('consultation and analysis only', $captured);
        $this->assertStringNotContainsString('ONLY the JSON object', $captured);
    }

    public function test_system_prompt_distinguishes_project_from_platform(): void
    {
        $captured = null;
        $this->mockCliproxy('{"estimate_points":5,"rationale":"Ok."}', function ($systemPrompt) use (&$captured) {
            $captured = $systemPrompt;
        });
        app(AgentProfileClient::class)->sendMessage('qa-engineer', 'Vote.', 'test-5');

        $this->assertNotNull($captured);
        // Project PO terbuka: file, konfigurasi, env, secret project boleh dibahas.
        $this->assertStringContainsString('TARGET PROJECT', $captured);
        $this->assertStringContainsString('environment variables, and secrets', $captured);
        // Platform tertutup: source code, server, AI, background tools.
        $this->assertStringContainsString('ScrumSpace platform itself', $captured);
        $this->assertStringContainsString('background tools', $captured);
        $this->assertStringContainsString(ChatDisclosurePolicy::SAFE_RESPONSE, $captured);
    }
}
