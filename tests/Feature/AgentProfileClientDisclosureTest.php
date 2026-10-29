<?php

namespace Tests\Feature;

use App\Services\AgentProfileClient;
use App\Services\ChatDisclosurePolicy;
use App\Services\CliProxyApiClient;
use Mockery;
use Tests\TestCase;

class AgentProfileClientDisclosureTest extends TestCase
{
    private function mockCliproxy(string $text): void
    {
        $cliproxy = Mockery::mock(CliProxyApiClient::class);
        $cliproxy->shouldReceive('resolveModel')->with('qa-engineer')->andReturn('gemini-test');
        $cliproxy->shouldReceive('chatResilient')->andReturn(['text' => $text, 'error' => null, 'model' => 'gemini-test']);
        $this->app->instance(CliProxyApiClient::class, $cliproxy);
    }

    public function test_chat_filter_replaces_sensitive_vote_with_safe_response_by_default(): void
    {
        // Rationale QA yang sah tapi mengandung kata sensitif ("server",
        // "credentials") — dengan filter aktif, seluruh jawaban diganti
        // kalimat penolakan (kasus QA US-01 Run #5).
        $this->mockCliproxy('{"estimate_points":5,"rationale":"Perlu uji di server dan cek credentials flow."}');
        $result = app(AgentProfileClient::class)->sendMessage('qa-engineer', 'Vote.', 'test-1');
        $this->assertSame(ChatDisclosurePolicy::SAFE_RESPONSE, $result);
    }

    public function test_structured_vote_bypasses_chat_filter_when_flag_is_false(): void
    {
        $vote = '{"estimate_points":5,"rationale":"Perlu uji di server dan cek credentials flow."}';
        $this->mockCliproxy($vote);
        $result = app(AgentProfileClient::class)->sendMessage('qa-engineer', 'Vote.', 'test-2', false);
        $this->assertSame($vote, $result);
    }
}
