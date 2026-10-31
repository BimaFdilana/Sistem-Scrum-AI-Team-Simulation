<?php

namespace Tests\Feature;

use App\Jobs\BreakdownExecutionJob;
use App\Models\ExecutionRun;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use App\Services\ChatDisclosurePolicy;
use App\Services\CliProxyApiClient;
use App\Services\HermesClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class BreakdownExecutionTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(): ExecutionRun
    {
        $owner = User::factory()->create();
        $project = Project::query()->create(['name' => 'Estimasi', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'SETUP_REQUIRED']);
        $story = Story::query()->create([
            'project_id' => $project->id,
            'title' => 'Tambah tombol kembali ke atas',
            'purpose' => 'Agar pengguna mudah kembali ke atas.',
            'acceptance_criteria' => "Tombol muncul setelah scroll.\nKlik menggulir halus ke atas.",
            'estimate_points' => 3,
        ]);

        return ExecutionRun::query()->create([
            'story_id' => $story->id, 'requested_by' => $owner->id, 'status' => 'QUEUED',
        ]);
    }

    private function breakdownWith(string $smAnswer): ExecutionRun
    {
        $run = $this->makeRun();
        $fake = Mockery::mock(HermesClient::class);
        $fake->shouldReceive('sendMessage')->once()->andReturn($smAnswer);
        (new BreakdownExecutionJob($run->id))->handle($fake, app(ChatDisclosurePolicy::class));

        return $run->fresh();
    }

    private function tasksJson(): string
    {
        return json_encode(['tasks' => [
            ['title' => 'Tambah tombol', 'description' => 'Buat komponen tombol.', 'assignee' => 'frontend-developer', 'files' => ['resources/js/back-to-top.js']],
        ]]);
    }

    public function test_fenced_breakdown_json_is_accepted(): void
    {
        // Regresi eksekusi US-01 #1: SM membungkus JSON dengan fence —
        // dulu json_decode ketat langsung gagal (BREAKDOWN_FAILED).
        $run = $this->breakdownWith("Berikut breakdown-nya:\n```json\n".$this->tasksJson()."\n```");

        $this->assertSame('AWAITING_PERMISSION', $run->status);
        $this->assertSame(1, $run->tasks()->count());
    }

    public function test_prose_wrapped_breakdown_json_is_accepted(): void
    {
        $run = $this->breakdownWith('Tentu, ini pembagiannya: '.$this->tasksJson().' Semoga membantu.');

        $this->assertSame('AWAITING_PERMISSION', $run->status);
        $this->assertSame(1, $run->tasks()->count());
    }

    public function test_refusal_text_fails_breakdown_without_tasks(): void
    {
        try {
            $this->breakdownWith(ChatDisclosurePolicy::SAFE_RESPONSE);
            $this->fail('Breakdown dari jawaban bukan JSON harus gagal.');
        } catch (\Throwable) {
            // Diharapkan: handle() melempar ulang setelah menandai FAILED.
        }

        $run = ExecutionRun::query()->latest('id')->firstOrFail();
        $this->assertSame('FAILED', $run->status);
        $this->assertSame('BREAKDOWN_FAILED', $run->error_code);
        $this->assertSame(0, $run->tasks()->count());
    }

    public function test_scrum_master_prompt_distinguishes_project_from_platform(): void
    {
        $captured = null;
        // sendMessage SM kini ketat: mock snapshot terverifikasi +
        // katalog gateway, bukan resolveModel.
        $config = Mockery::mock(\App\Services\AgentConfiguration::class);
        $config->shouldReceive('current')->with('scrum-master')->andReturn(['status' => 'verified', 'model' => 'gemini-test']);
        $this->app->instance(\App\Services\AgentConfiguration::class, $config);
        $cliproxy = Mockery::mock(CliProxyApiClient::class);
        $cliproxy->shouldReceive('models')->andReturn([['id' => 'gemini-test', 'owned_by' => 'google']]);
        $cliproxy->shouldReceive('chatResilient')->withArgs(function ($model, $systemPrompt, $message) use (&$captured) {
            $captured = $systemPrompt;

            return true;
        })->andReturn(['text' => $this->tasksJson(), 'error' => null, 'model' => 'gemini-test']);
        $this->app->instance(CliProxyApiClient::class, $cliproxy);

        app(HermesClient::class)->sendMessage('Pecah story.', 'test-breakdown');

        $this->assertNotNull($captured);
        // Project PO terbuka — termasuk mendaftar files yang diubah.
        $this->assertStringContainsString('TARGET PROJECT', $captured);
        $this->assertStringContainsString('listing the project files a task will change', $captured);
        // Platform tertutup.
        $this->assertStringContainsString('ScrumSpace platform itself', $captured);
        $this->assertStringContainsString(ChatDisclosurePolicy::SAFE_RESPONSE, $captured);
    }
}
