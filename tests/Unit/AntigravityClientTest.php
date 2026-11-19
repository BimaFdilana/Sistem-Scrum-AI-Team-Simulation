<?php

namespace Tests\Unit;

use App\Services\AntigravityClient;
use PHPUnit\Framework\TestCase;

class AntigravityClientTest extends TestCase
{
    private function stream(array $result): string
    {
        return implode("\n", [
            json_encode(['event' => 'init', 'init' => ['cwd' => '/tmp']]),
            json_encode(['event' => 'result', 'result' => $result]),
        ]);
    }

    public function test_successful_result_is_read_from_the_last_result_event(): void
    {
        $outcome = (new AntigravityClient)->interpret(0, $this->stream([
            'conversation_id' => 'c-1', 'status' => 'SUCCESS', 'response' => "Hijau\n",
            'usage' => ['input_tokens' => 10, 'output_tokens' => 2, 'thinking_tokens' => 3, 'total_tokens' => 15],
        ]), '');

        $this->assertSame('ok', $outcome['status']);
        $this->assertSame('Hijau', $outcome['text']);
        $this->assertSame('c-1', $outcome['result']['conversation_id']);
    }

    public function test_unknown_model_is_a_model_problem(): void
    {
        $outcome = (new AntigravityClient)->interpret(1, json_encode([
            'conversation_id' => '', 'status' => 'ERROR', 'response' => '',
            'error' => 'invalid model selection (--model "x"): model x is not recognized as a known model or custom model in settings',
        ]), 'error: invalid model selection');

        $this->assertSame('permanent', $outcome['status']);
        $this->assertSame('MODEL_PROBLEMATIC', $outcome['error']);
    }

    public function test_print_timeout_is_transient(): void
    {
        $stderr = "[agy] print timeout after 2s\nAGY_ERROR: {\"short_error\":\"print timeout after 2s\",\"status\":\"DEADLINE_EXCEEDED\",\"retryable\":false,\"reason\":\"print_timeout\"}";
        $outcome = (new AntigravityClient)->interpret(3, $this->stream(['status' => 'ERROR', 'response' => '', 'error' => 'print timeout after 2s']), $stderr);

        $this->assertSame('transient', $outcome['status']);
        $this->assertSame('TIMEOUT', $outcome['error']);
    }

    public function test_not_logged_in_and_quota_errors_are_permanent(): void
    {
        $client = new AntigravityClient;

        $this->assertSame('NOT_LOGGED_IN', $client->interpret(1, '', 'W1119 cache.go:157] error getting token source: You are not logged into Antigravity.')['error']);
        $this->assertSame('QUOTA_EXHAUSTED', $client->interpret(1, $this->stream(['status' => 'ERROR', 'response' => '', 'error' => 'RESOURCE_EXHAUSTED: quota exceeded']), '')['error']);
    }

    public function test_success_without_text_is_an_empty_response(): void
    {
        $outcome = (new AntigravityClient)->interpret(0, $this->stream(['status' => 'SUCCESS', 'response' => '  ']), '');

        $this->assertSame('transient', $outcome['status']);
        $this->assertSame('EMPTY_RESPONSE', $outcome['error']);
    }

    public function test_models_output_is_parsed_and_noise_is_ignored(): void
    {
        $models = (new AntigravityClient)->parseModels("Fetching available models...\ngemini-3.8-flash-high\tGemini 3.8 Flash (High)\nclaude-sonnet-4-6\tClaude Sonnet 4.6 (Thinking)\n\n");

        $this->assertSame([
            ['id' => 'gemini-3.8-flash-high', 'name' => 'Gemini 3.8 Flash (High)'],
            ['id' => 'claude-sonnet-4-6', 'name' => 'Claude Sonnet 4.6 (Thinking)'],
        ], $models);
    }

    public function test_usage_is_mapped_to_prompt_and_completion_tokens(): void
    {
        $usage = (new AntigravityClient)->normalizeUsage(['input_tokens' => 100, 'output_tokens' => 20, 'thinking_tokens' => 30, 'total_tokens' => 150]);

        $this->assertSame(['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150], $usage);
        $this->assertSame([], (new AntigravityClient)->normalizeUsage([]));
    }

    public function test_quota_groups_are_read_from_the_slash_command_result(): void
    {
        $outcome = (new AntigravityClient)->interpret(0, json_encode([
            'conversation_id' => '', 'status' => 'SUCCESS', 'response' => "Gemini Models\tWeekly Limit Remaining\t100%\n",
            'command' => ['name' => 'usage', 'data' => ['groups' => [
                ['name' => 'Gemini Models', 'description' => 'Models within this group: Gemini 3.8 Flash', 'buckets' => [
                    ['id' => 'gemini-weekly', 'window' => 'weekly', 'remaining_fraction' => 0.9981, 'reset_time' => '2026-11-26T11:54:26Z'],
                    ['id' => 'gemini-5h', 'window' => '5h', 'remaining_fraction' => 0.42, 'reset_time' => '2026-11-20T00:45:43Z'],
                ]],
                ['name' => 'Kosong', 'buckets' => [['window' => 'monthly', 'remaining_fraction' => 1]]],
            ]]],
        ]), '');

        $groups = (new AntigravityClient)->parseQuota($outcome['result']);

        $this->assertCount(1, $groups);
        $this->assertSame('Gemini 3.8 Flash', $groups[0]['models']);
        $this->assertSame(['5h', 'weekly'], array_column($groups[0]['buckets'], 'window'));
        $this->assertSame(0.42, $groups[0]['buckets'][0]['remaining']);
        $this->assertSame([], (new AntigravityClient)->parseQuota(['response' => 'tanpa data']));
    }

    public function test_developer_permissions_stay_inside_the_worktree_and_block_git(): void
    {
        $settings = (new AntigravityClient)->taskSettings('/srv/wt/task-1');

        $this->assertContains('write_file(/srv/wt/task-1)', $settings['permissions']['allow']);
        $this->assertContains('command(git)', $settings['permissions']['deny']);
        $this->assertContains('command(sudo)', $settings['permissions']['deny']);
        $this->assertContains('write_file(/srv/wt/task-1/.env)', $settings['permissions']['deny']);
    }
}
