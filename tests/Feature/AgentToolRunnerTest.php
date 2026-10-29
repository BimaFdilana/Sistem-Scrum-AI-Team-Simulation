<?php

namespace Tests\Feature;

use App\Services\AgentToolRunner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentToolRunnerTest extends TestCase
{
    private string $worktree;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.cliproxy.agents_enabled' => true, 'ai.cliproxy.api_key' => 'test-key']);
        Cache::forget('cliproxy:models');
        $this->worktree = storage_path('framework/testing/tool-runner-'.uniqid());
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

    private function toolCall(string $name, array $arguments): array
    {
        return ['choices' => [[
            'message' => [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_'.uniqid(),
                    'function' => ['name' => $name, 'arguments' => json_encode($arguments)],
                ]],
            ],
        ]]];
    }

    public function test_apply_edit_and_submit_changes_file(): void
    {
        $this->fakeGateway([
            $this->toolCall('apply_edit', ['path' => 'hello.txt', 'old_text' => 'hello', 'new_text' => 'hello world']),
            $this->toolCall('submit_task', ['summary' => 'Ubah salam.']),
        ]);

        $result = app(AgentToolRunner::class)->run('frontend-developer', $this->worktree, 'Ubah salam.', true);

        $this->assertSame('hello world', file_get_contents($this->worktree.'/hello.txt'));
        $this->assertSame(['hello.txt'], $result['files']);
        $this->assertSame('Ubah salam.', $result['summary']);
    }

    public function test_mismatched_old_text_is_reported_and_model_can_correct(): void
    {
        $this->fakeGateway([
            $this->toolCall('apply_edit', ['path' => 'hello.txt', 'old_text' => 'tidak ada', 'new_text' => 'x']),
            $this->toolCall('apply_edit', ['path' => 'hello.txt', 'old_text' => 'hello', 'new_text' => 'hello lagi']),
            $this->toolCall('submit_task', ['summary' => 'Perbaiki setelah koreksi.']),
        ]);

        $result = app(AgentToolRunner::class)->run('frontend-developer', $this->worktree, 'Ubah salam.', true);

        $this->assertSame('hello lagi', file_get_contents($this->worktree.'/hello.txt'));
        $this->assertSame('Perbaiki setelah koreksi.', $result['summary']);
    }

    public function test_delete_is_rejected_on_repair_orders(): void
    {
        $this->fakeGateway([
            $this->toolCall('delete_file', ['path' => 'hello.txt']),
            $this->toolCall('submit_task', ['summary' => 'Tidak ada perubahan.']),
        ]);

        $result = app(AgentToolRunner::class)->run('frontend-developer', $this->worktree, 'Hapus file.', false);

        $this->assertFileExists($this->worktree.'/hello.txt');
        $this->assertSame([], $result['files']);
    }

    public function test_php_lint_failure_reverts_the_edit(): void
    {
        $this->fakeGateway([
            $this->toolCall('create_file', ['path' => 'bad.php', 'content' => '<?php this is not valid php {{{']),
            $this->toolCall('submit_task', ['summary' => 'Selesai tanpa perubahan.']),
        ]);

        $result = app(AgentToolRunner::class)->run('frontend-developer', $this->worktree, 'Buat file php.', true);

        $this->assertFileDoesNotExist($this->worktree.'/bad.php');
        $this->assertSame([], $result['files']);
    }

    public function test_path_traversal_is_rejected(): void
    {
        $this->fakeGateway([
            $this->toolCall('create_file', ['path' => '../evil.php', 'content' => 'x']),
            $this->toolCall('submit_task', ['summary' => 'Selesai tanpa perubahan.']),
        ]);

        $result = app(AgentToolRunner::class)->run('frontend-developer', $this->worktree, 'Buat file.', true);

        $this->assertSame([], $result['files']);
        $this->assertFileDoesNotExist(dirname($this->worktree).'/evil.php');
    }
}
