<?php

namespace Tests\Feature;

use App\Models\AiUsageRecord;
use App\Models\User;
use App\Services\AiUsageRecorder;
use App\Services\CliProxyApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiProviderUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_tool_call_records_token_usage(): void
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
                            'function' => ['name' => 'apply_edit', 'arguments' => json_encode(['path' => 'a.php', 'old_text' => 'x', 'new_text' => 'y'])],
                        ]],
                    ],
                ]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]),
        ]);

        app(CliProxyApiClient::class)->chatWithTools(
            'gemini-3.1-flash-lite', 'sys', 'hi',
            [['type' => 'function', 'function' => ['name' => 'apply_edit', 'parameters' => ['type' => 'object']]]],
        );

        $record = AiUsageRecord::query()->first();
        $this->assertNotNull($record);
        $this->assertSame('Google', $record->provider);
        $this->assertSame('gemini-3.1-flash-lite', $record->model);
        $this->assertSame(15, $record->total_tokens);
    }

    public function test_usage_summary_groups_by_provider_and_model(): void
    {
        AiUsageRecord::query()->create(['provider' => 'Google', 'model' => 'gemini-a', 'prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]);
        AiUsageRecord::query()->create(['provider' => 'Google', 'model' => 'gemini-a', 'prompt_tokens' => 5, 'completion_tokens' => 5, 'total_tokens' => 10]);
        AiUsageRecord::query()->create(['provider' => 'Anthropic', 'model' => 'claude-x', 'prompt_tokens' => 7, 'completion_tokens' => 3, 'total_tokens' => 10]);

        $summary = AiUsageRecorder::summary(30);

        $this->assertSame(35, $summary['grand_total']);
        $this->assertSame('Google', $summary['providers'][0]['provider']);
        $this->assertSame(25, $summary['providers'][0]['total']);
        $this->assertSame(2, $summary['providers'][0]['calls']);
        $this->assertSame('gemini-a', $summary['models'][0]['model']);
    }

    public function test_ai_providers_page_shows_usage_inside_provider_card(): void
    {
        $user = User::factory()->create();
        AiUsageRecord::query()->create(['provider' => 'Google', 'model' => 'gemini-a', 'prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]);

        $this->actingAs($user)->get('/ai-providers')
            ->assertOk()
            ->assertSee('Pemakaian 30 hari')
            ->assertSee('Google')
            ->assertSee('15')
            ->assertDontSee('PEMAKAIAN TOKEN');
    }

    public function test_ai_providers_page_shows_other_provider_usage_separately(): void
    {
        $user = User::factory()->create();
        AiUsageRecord::query()->create(['provider' => 'Mimo', 'model' => 'mimo-7b', 'prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]);

        $this->actingAs($user)->get('/ai-providers')
            ->assertOk()
            ->assertSee('Pemakaian provider tanpa kartu di atas')
            ->assertSee('Mimo');
    }
}
