<?php

namespace Tests\Feature;

use App\Services\AiGateway;
use App\Services\CliProxyApiClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiGatewayTest extends TestCase
{
    public function test_transient_codes_are_retryable_and_permanent_codes_are_not(): void
    {
        foreach (['RATE_LIMITED', 'UPSTREAM_OVERLOADED', 'TIMEOUT', 'GATEWAY_UNREACHABLE', 'EMPTY_RESPONSE'] as $code) {
            $this->assertTrue(AiGateway::isTransient($code), $code);
        }
        foreach (['AUTH_FAILED', 'BAD_REQUEST', 'MODEL_NOT_FOUND', 'PROVIDER_AUTH_INCOMPLETE', 'DISABLED'] as $code) {
            $this->assertFalse(AiGateway::isTransient($code), $code);
        }
    }

    public function test_release_delay_grows_and_respects_retry_after_with_cap(): void
    {
        $this->assertSame(60, AiGateway::releaseDelay(1));
        $this->assertSame(120, AiGateway::releaseDelay(2));
        $this->assertSame(120, AiGateway::releaseDelay(1, 120));
        $this->assertSame(600, AiGateway::releaseDelay(1, 9999));
        $this->assertSame(600, AiGateway::releaseDelay(10));
    }

    public function test_chat_with_tools_returns_parsed_tool_calls_and_usage(): void
    {
        config(['ai.cliproxy.agents_enabled' => true, 'ai.cliproxy.api_key' => 'test-key']);
        Cache::forget('cliproxy:models');
        Http::fake([
            'http://127.0.0.1:8317/v1/models' => Http::response(['data' => [
                ['id' => 'gemini-3.1-flash-lite', 'owned_by' => 'google'],
            ]]),
            'http://127.0.0.1:8317/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'function' => [
                                'name' => 'apply_edit',
                                'arguments' => json_encode(['path' => 'a.php', 'old_text' => 'x', 'new_text' => 'y']),
                            ],
                        ]],
                    ],
                ]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]),
        ]);

        $result = app(CliProxyApiClient::class)->chatWithTools(
            'gemini-3.1-flash-lite',
            'You are a code agent.',
            'Call apply_edit.',
            [['type' => 'function', 'function' => [
                'name' => 'apply_edit',
                'parameters' => ['type' => 'object', 'properties' => [
                    'path' => ['type' => 'string'],
                    'old_text' => ['type' => 'string'],
                    'new_text' => ['type' => 'string'],
                ], 'required' => ['path', 'old_text', 'new_text']],
            ]]],
        );

        $this->assertNull($result['error']);
        $this->assertSame('apply_edit', $result['tool_calls'][0]['name']);
        $this->assertSame(['path' => 'a.php', 'old_text' => 'x', 'new_text' => 'y'], $result['tool_calls'][0]['arguments']);
        $this->assertSame(15, $result['usage']['total_tokens']);
    }

    public function test_chat_with_tools_reports_malformed_arguments_without_retry(): void
    {
        config(['ai.cliproxy.agents_enabled' => true, 'ai.cliproxy.api_key' => 'test-key']);
        Cache::forget('cliproxy:models');
        Http::fake([
            'http://127.0.0.1:8317/v1/models' => Http::response(['data' => [
                ['id' => 'gemini-3.1-flash-lite', 'owned_by' => 'google'],
            ]]),
            'http://127.0.0.1:8317/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'function' => ['name' => 'apply_edit', 'arguments' => '{bukan json'],
                        ]],
                    ],
                ]],
            ]),
        ]);

        $result = app(CliProxyApiClient::class)->chatWithTools(
            'gemini-3.1-flash-lite', 'sys', 'hi',
            [['type' => 'function', 'function' => ['name' => 'apply_edit', 'parameters' => ['type' => 'object']]]],
        );

        $this->assertSame('MALFORMED_TOOL_CALL', $result['error']);
        $this->assertSame([], $result['tool_calls']);
    }

    public function test_rate_limit_response_carries_retry_after(): void
    {
        config(['ai.cliproxy.agents_enabled' => true, 'ai.cliproxy.api_key' => 'test-key', 'ai.cliproxy.chat_attempts' => 1]);
        Cache::forget('cliproxy:models');
        Http::fake([
            'http://127.0.0.1:8317/v1/models' => Http::response(['data' => [
                ['id' => 'gemini-3.1-flash-lite', 'owned_by' => 'google'],
            ]]),
            'http://127.0.0.1:8317/v1/chat/completions' => Http::response(['error' => ['message' => 'rate limited']], 429, ['Retry-After' => '45']),
        ]);

        $result = app(CliProxyApiClient::class)->chatWithTools(
            'gemini-3.1-flash-lite', 'sys', 'hi',
            [['type' => 'function', 'function' => ['name' => 'apply_edit', 'parameters' => ['type' => 'object']]]],
            5,
        );

        // chat_attempts default 3 dengan sleep di antaranya; fake tetap 429.
        $this->assertSame('RATE_LIMITED', $result['error']);
        $this->assertSame(45, $result['retry_after']);
    }
}
