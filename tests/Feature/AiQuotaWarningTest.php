<?php

namespace Tests\Feature;

use App\Exceptions\AiUnavailableException;
use App\Jobs\ExecuteExecutionTaskJob;
use App\Models\ExecutionRun;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use App\Services\AgentToolRunner;
use App\Services\CliProxyApiClient;
use App\Support\Ai\AiQuota;
use App\Support\Workspace\WorktreeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Kuota AI habis tidak lagi diam-diam: PO diberi tahu dan eksekusi dijeda rapi. */
class AiQuotaWarningTest extends TestCase
{
    use RefreshDatabase;

    public function test_quota_text_is_recognised_but_plain_busy_is_not(): void
    {
        $this->assertTrue(AiQuota::isQuotaText('Error: Resource has been exhausted (e.g. check quota).'));
        $this->assertTrue(AiQuota::isQuotaText('You exceeded your current quota'));
        $this->assertFalse(AiQuota::isQuotaText('Server overloaded, try again'));
    }

    public function test_gateway_quota_429_is_not_retried_and_warns_everywhere_until_model_works_again(): void
    {
        $user = User::factory()->create();
        config(['ai.cliproxy.agents_enabled' => true, 'ai.cliproxy.base_url' => 'http://127.0.0.1:8317', 'ai.cliproxy.api_key' => 'k']);
        Http::fake([
            'http://127.0.0.1:8317/v1/chat/completions' => Http::sequence()
                ->push(['error' => ['message' => 'Resource has been exhausted (e.g. check quota).']], 429)
                ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => 'ok']]]], 200),
        ]);
        $client = app(CliProxyApiClient::class);

        $first = $client->chatResilient('gemini-3.5-flash-lite', 'sys', 'halo');
        $this->assertNull($first['text']);
        $this->assertSame('QUOTA_EXHAUSTED', $first['error']);
        Http::assertSentCount(1); // tidak diulang buta

        $this->assertSame('gemini-3.5-flash-lite', AiQuota::current()['model']);
        $this->assertSame(1, Notification::query()->where('type', 'ai_quota')->where('user_id', $user->id)->count());
        $this->actingAs($user)->get('/projects')->assertOk()->assertSee('Kuota AI habis')->assertSee('Ganti model');
        $this->actingAs($user)->getJson(route('activity.active'))->assertOk()->assertJsonPath('alert', fn ($text) => str_contains((string) $text, 'gemini-3.5-flash-lite'));

        AiQuota::exhausted('gemini-3.5-flash-lite', 'lagi'); // kejadian sama: tidak menambah notifikasi
        $this->assertSame(1, Notification::query()->where('type', 'ai_quota')->count());

        $client->chatResilient('gemini-3.5-flash-lite', 'sys', 'halo lagi');
        $this->assertNull(AiQuota::current()); // berhasil lagi: peringatan hilang
        $this->actingAs($user)->get('/projects')->assertOk()->assertDontSee('Kuota AI habis');
    }

    public function test_execution_is_paused_not_failed_when_quota_runs_out_and_can_be_resumed(): void
    {
        Queue::fake();
        $baseline = storage_path('framework/testing/quota-'.uniqid());
        mkdir($baseline, 0700, true);
        file_put_contents($baseline.'/README.md', 'baseline');
        $owner = User::factory()->create();
        $project = Project::query()->create(['name' => 'Q', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'BASELINE_READY', 'workspace_path' => $baseline]);
        $story = Story::query()->create(['project_id' => $project->id, 'title' => 'S', 'estimate_points' => 3]);
        $run = ExecutionRun::query()->create(['story_id' => $story->id, 'requested_by' => $owner->id, 'status' => 'EXECUTING']);
        $task = $run->tasks()->create(['title' => 'T', 'description' => 'D', 'assignee' => 'backend-developer', 'status' => 'PENDING']);

        $runner = $this->mock(AgentToolRunner::class, function ($mock): void {
            $mock->shouldReceive('run')->andThrow(new AiUnavailableException('QUOTA_EXHAUSTED', 'Kuota habis'));
        });
        (new ExecuteExecutionTaskJob($run->id, $task->id))->handle(new WorktreeManager, $runner);

        $run->refresh();
        $this->assertSame(ExecutionRun::NEEDS_PO, $run->status);
        $this->assertSame('QUOTA_EXHAUSTED', $run->error_code);
        $this->assertStringContainsString('Lanjutkan', (string) $run->summary);
        $this->assertSame('BLOCKED', $task->fresh()->status);
        $this->assertNotNull(AiQuota::current());

        $this->actingAs($owner)->post(route('stories.executions.resume', [$story, $run]))->assertRedirect();
        $this->assertSame('EXECUTING', $run->fresh()->status);
        $this->assertSame('PENDING', $task->fresh()->status);
    }
}
