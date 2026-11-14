<?php

namespace Tests\Feature;

use App\Models\ExecutionRun;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use App\Support\Ui\FriendlyText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Nama alat internal (OpenCode, Hermes, kanban, CLIProxyAPI) tidak tampil ke PO. */
class FriendlyTextTest extends TestCase
{
    use RefreshDatabase;

    public function test_tool_names_raw_process_output_and_codes_become_neutral(): void
    {
        $raw = 'Task #81 gagal: The process "\'/usr/bin/opencode\' \'run\' \'Kamu Backend Developer. Kerjakan task panjang...\'" exceeded the timeout of 1500 seconds.';
        $this->assertSame('Task #81 gagal: Proses developer AI melewati batas waktu (25 menit).', FriendlyText::clean($raw));

        $mixed = FriendlyText::clean('Fallback otomatis: OpenCode gagal (exit 1): [0m [91mError: [0mResource has been exhausted. Task dilanjutkan lewat Hermes. Kartu kanban t_1. Pastikan CLIProxyAPI berjalan.');
        foreach (['OpenCode', 'Hermes', 'kanban', 'CLIProxyAPI', '[0m', '[91m'] as $hidden) {
            $this->assertStringNotContainsStringIgnoringCase($hidden, $mixed);
        }
        $this->assertStringContainsString('agen cadangan', $mixed);
        $this->assertStringContainsString('Resource has been exhausted', $mixed); // penyebab tetap terbaca

        $this->assertSame('DEVELOPER_AI_GAGAL', FriendlyText::code('OPENCODE_FAILED'));
        $this->assertSame('EXECUTION_FAILED', FriendlyText::code('EXECUTION_FAILED'));
    }

    public function test_execution_page_shows_old_messages_without_tool_names(): void
    {
        $user = User::factory()->create();
        $project = Project::query()->create(['name' => 'P', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'BASELINE_READY']);
        $story = Story::create(['project_id' => $project->id, 'title' => 'S', 'estimate_points' => 3]);
        $run = ExecutionRun::query()->create(['story_id' => $story->id, 'requested_by' => $user->id, 'status' => 'FAILED', 'error_code' => 'OPENCODE_FAILED', 'summary' => 'OpenCode gagal lalu Hermes', 'mode' => 'worktree']);
        $run->tasks()->create(['title' => 'T', 'assignee' => 'backend-developer', 'status' => 'FAILED', 'executor' => 'hermes', 'last_error' => 'OPENCODE_FALLBACK']);
        $run->messages()->create(['sender_slug' => 'scrum-master', 'recipient_slug' => 'team', 'content' => 'Task #1 dikirim ke OpenCode (agen coding eksternal).']);

        $html = $this->actingAs($user)->get(route('stories.executions.show', [$story, $run]))->assertOk()->getContent();
        $visible = strip_tags(preg_replace('/<script[\s\S]*?<\/script>|<style[\s\S]*?<\/style>|class="[^"]*"/', '', $html));

        foreach (['OpenCode', 'Hermes', 'OPENCODE'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $visible);
        }
        $this->assertStringContainsString('developer AI', $visible);
        $this->assertStringContainsString('Agen cadangan', $visible);
    }
}
