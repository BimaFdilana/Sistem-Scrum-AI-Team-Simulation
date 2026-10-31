<?php

namespace Tests\Feature;

use App\Models\AgentToolRound;
use App\Models\AiUsageRecord;
use App\Services\AgentToolRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Diagnosis eksekusi #1 (31 Okt 2026): transkrip per putaran dan tautan
 * run/task tidak ada di data. Tes ini mengunci perbaikan itu: setiap
 * putaran loop yang dipanggil dengan konteks harus tercatat, dan catatan
 * pemakaian harus membawa konteks yang sama.
 */
class AgentToolRoundLoggingTest extends TestCase
{
    use RefreshDatabase;

    private string $worktree;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.cliproxy.agents_enabled' => true, 'ai.cliproxy.api_key' => 'test-key']);
        Cache::forget('cliproxy:models');
        // run() kini ketat: perlu snapshot menu terverifikasi.
        $this->mock(\App\Services\AgentConfiguration::class, function (\Mockery\MockInterface $mock) {
            $mock->shouldReceive('current')->andReturn(['status' => 'verified', 'model' => 'gemini-3.1-flash-lite']);
        });
        $this->worktree = storage_path('framework/testing/tool-rounds-'.uniqid());
        mkdir($this->worktree, 0700, true);
        file_put_contents($this->worktree.'/hello.txt', 'hello');
    }

    private function fakeGateway(array $chatResponses): void
    {
        Cache::forget('cliproxy:models');
        $sequence = Http::sequence();
        foreach ($chatResponses as $response) {
            $sequence->push($response);
        }
        Http::fake([
            'http://127.0.0.1:8317/v1/models' => Http::response(['data' => [
                ['id' => 'gemini-3.1-flash-lite', 'owned_by' => 'google'],
            ]]),
            'http://127.0.0.1:8317/v1/chat/completions' => $sequence,
        ]);
    }

    private function toolCall(string $name, array $arguments, ?array $usage = null): array
    {
        $response = ['choices' => [[
            'message' => [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_'.uniqid(),
                    'function' => ['name' => $name, 'arguments' => json_encode($arguments)],
                ]],
            ],
        ]]];
        if ($usage !== null) {
            $response['usage'] = $usage;
        }

        return $response;
    }

    public function test_rounds_are_recorded_with_context(): void
    {
        $this->fakeGateway([
            $this->toolCall('read_file', ['path' => 'hello.txt']),
            $this->toolCall('create_file', ['path' => 'PROBE.md', 'content' => 'probe ok']),
            $this->toolCall('submit_task', ['summary' => 'Selesai.']),
        ]);

        $result = app(AgentToolRunner::class)->run('frontend-developer', $this->worktree, 'Kerjakan.', true, [
            'execution_run_id' => 7,
            'execution_task_id' => 9,
            'source' => 'execution',
        ]);

        $this->assertSame('Selesai.', $result['summary']);
        $rounds = AgentToolRound::query()->orderBy('round')->get();
        $this->assertCount(3, $rounds);
        $this->assertSame([1, 2, 3], $rounds->pluck('round')->all());
        $this->assertSame(['read_file', 'create_file', 'submit_task'], $rounds->map(
            fn ($round) => $round->tool_calls[0]['name'] ?? null
        )->all());
        $this->assertTrue($rounds->every(fn ($round) => $round->model === 'gemini-3.1-flash-lite'
            && $round->execution_run_id === 7
            && $round->execution_task_id === 9
            && $round->source === 'execution'));
    }

    public function test_usage_records_carry_execution_context(): void
    {
        $usage = ['prompt_tokens' => 100, 'completion_tokens' => 10, 'total_tokens' => 110];
        $this->fakeGateway([
            $this->toolCall('apply_edit', ['path' => 'hello.txt', 'old_text' => 'hello', 'new_text' => 'hello lagi'], $usage),
            $this->toolCall('submit_task', ['summary' => 'Selesai.'], $usage),
        ]);

        app(AgentToolRunner::class)->run('frontend-developer', $this->worktree, 'Kerjakan.', true, [
            'execution_run_id' => 7,
            'execution_task_id' => 9,
            'source' => 'execution',
        ]);

        $records = AiUsageRecord::query()->where('execution_run_id', 7)->get();
        $this->assertCount(2, $records);
        $this->assertTrue($records->every(fn ($record) => $record->execution_task_id === 9
            && $record->source === 'execution'
            && $record->total_tokens === 110));
    }

    public function test_no_rounds_without_context(): void
    {
        $this->fakeGateway([
            $this->toolCall('apply_edit', ['path' => 'hello.txt', 'old_text' => 'hello', 'new_text' => 'hello lagi']),
            $this->toolCall('submit_task', ['summary' => 'Selesai.']),
        ]);

        app(AgentToolRunner::class)->run('frontend-developer', $this->worktree, 'Kerjakan.', true);

        $this->assertSame(0, AgentToolRound::query()->count());
    }

    public function test_probe_command_reports_rounds(): void
    {
        $this->fakeGateway([
            $this->toolCall('read_file', ['path' => 'hello.txt']),
            $this->toolCall('create_file', ['path' => 'PROBE.md', 'content' => 'probe ok']),
            $this->toolCall('submit_task', ['summary' => 'Probe selesai.']),
        ]);

        $this->artisan('agent:probe', ['--model' => 'gemini-3.1-flash-lite'])
            ->expectsOutputToContain('HASIL: BERHASIL')
            ->expectsOutputToContain('Putaran 1')
            ->expectsOutputToContain('Putaran 3')
            ->assertExitCode(0);

        $this->assertSame(3, AgentToolRound::query()->where('source', 'probe')->count());
    }

    public function test_probe_command_failure_says_no_rounds_were_recorded(): void
    {
        Cache::forget('cliproxy:models');
        Http::fake([
            'http://127.0.0.1:8317/v1/models' => Http::response(['data' => [
                ['id' => 'gemini-3.1-flash-lite', 'owned_by' => 'google'],
            ]]),
            'http://127.0.0.1:8317/v1/chat/completions' => Http::response(['error' => ['message' => 'unknown provider']], 400),
        ]);

        $this->artisan('agent:probe', ['--model' => 'gemini-3.1-flash-lite'])
            ->expectsOutputToContain('HASIL: GAGAL')
            ->expectsOutputToContain('Putaran tercatat: 0')
            ->expectsOutputToContain('Tidak ada putaran tercatat')
            ->assertExitCode(1);

        $this->assertSame(0, AgentToolRound::query()->where('source', 'probe')->count());
    }
}
