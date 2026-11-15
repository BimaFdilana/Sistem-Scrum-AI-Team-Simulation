<?php

namespace Tests\Feature;

use App\Jobs\ExecuteExecutionTaskJob;
use App\Jobs\UnderstandChatCommandJob;
use App\Models\ChatCommand;
use App\Models\ChatMessage;
use App\Models\ExecutionRun;
use App\Models\ExecutionTask;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Story;
use App\Models\User;
use App\Services\AgentToolRunner;
use App\Support\Chat\SprintChat;
use App\Support\Chat\StopIntent;
use App\Support\Execution\ExecutionStopper;
use App\Support\Execution\OpenCodeRunner;
use App\Support\Workspace\WorktreeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Tanya Scrum Master: PO bisa menghentikan eksekusi secara paksa lewat chat atau tombol. */
class StopExecutionTest extends TestCase
{
    use RefreshDatabase;

    private function setupRun(string $status = 'EXECUTING'): array
    {
        $dir = storage_path('framework/testing/stop-'.uniqid());
        mkdir($dir, 0700, true);
        $project = Project::query()->create(['name' => 'SIPUA', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'BASELINE_READY', 'workspace_path' => $dir]);
        $sprint = Sprint::create(['backlog_id' => $project->backlog()->firstOrCreate([], ['name' => 'B'])->id, 'project_id' => $project->id, 'name' => 'Sprint 2', 'status' => 'ACTIVE', 'revision' => 1]);
        $story = Story::create(['project_id' => $project->id, 'title' => 'Perbaiki pagu', 'origin' => 'CHAT']);
        $user = User::factory()->create();
        $run = ExecutionRun::query()->create(['story_id' => $story->id, 'requested_by' => $user->id, 'status' => $status, 'mode' => 'worktree']);
        $running = ExecutionTask::query()->create(['execution_run_id' => $run->id, 'title' => 'BE', 'assignee' => 'backend-developer', 'status' => 'IN_PROGRESS']);
        $pending = ExecutionTask::query()->create(['execution_run_id' => $run->id, 'title' => 'FE', 'assignee' => 'frontend-developer', 'status' => 'PENDING']);
        $done = ExecutionTask::query()->create(['execution_run_id' => $run->id, 'title' => 'Sudah', 'assignee' => 'backend-developer', 'status' => 'APPROVED']);

        return compact('project', 'sprint', 'story', 'user', 'run', 'running', 'pending', 'done');
    }

    public function test_stop_intent_is_strict(): void
    {
        foreach (['hentikan eksekusi', 'Hentikan eksekusi sekarang juga', 'stop', 'Tolong hentikan proses', 'stop semua', 'batalkan eksekusi', 'hentikan paksa', 'Scrum Master, hentikan tim', 'matikan proses itu', 'stop perintah 49', 'berhentikan eksekusi'] as $yes) {
            $this->assertTrue(StopIntent::matches($yes), $yes);
        }
        foreach (['Tambahkan tombol batalkan pesanan di halaman checkout', 'Perbaiki validasi pagu lalu hentikan eksekusi lama', 'bagaimana status eksekusi?', 'jangan hentikan eksekusi', 'stop loading spinner di halaman usulan anggaran unit kerja', 'ulangi PERINTAH-47'] as $no) {
            $this->assertFalse(StopIntent::matches($no), $no);
        }
    }

    public function test_chat_command_stops_active_run_cancels_open_tasks_and_replies(): void
    {
        Bus::fake([UnderstandChatCommandJob::class]);
        ['sprint' => $sprint, 'user' => $user, 'run' => $run, 'running' => $running, 'pending' => $pending, 'done' => $done] = $this->setupRun();

        $this->actingAs($user)->postJson(route('chat.commands.store'), ['message' => 'hentikan eksekusi', 'sprint_id' => $sprint->id])
            ->assertOk()->assertJsonPath('stopped', 1);

        Bus::assertNotDispatched(UnderstandChatCommandJob::class); // tanpa AI
        $run->refresh();
        $this->assertSame('FAILED', $run->status);
        $this->assertSame(ExecutionStopper::CODE, $run->error_code);
        $this->assertNotNull($run->completed_at);
        $this->assertSame('CANCELLED', $running->fresh()->status);
        $this->assertSame('CANCELLED', $pending->fresh()->status);
        $this->assertSame('APPROVED', $done->fresh()->status); // yang sudah beres tidak disentuh

        $reply = ChatMessage::query()->where('role', 'assistant')->latest('id')->first();
        $this->assertStringContainsString('dihentikan paksa', $reply->content);
        $this->assertSame(ChatCommand::STOPPED, ChatCommand::query()->latest('id')->first()->phase);
        // Penanda henti untuk proses agen yang sedang jalan.
        $this->assertTrue(ExecutionStopper::stopRequested($run->project()->workspace_path));
    }

    public function test_stop_also_closes_a_run_waiting_for_po_and_reports_when_nothing_runs(): void
    {
        Bus::fake([UnderstandChatCommandJob::class]);
        ['sprint' => $sprint, 'user' => $user, 'run' => $run] = $this->setupRun(ExecutionRun::NEEDS_PO);

        $this->actingAs($user)->postJson(route('chat.commands.store'), ['message' => 'stop', 'sprint_id' => $sprint->id])->assertOk()->assertJsonPath('stopped', 1);
        $this->assertSame('FAILED', $run->fresh()->status);

        $this->actingAs($user)->postJson(route('chat.commands.store'), ['message' => 'stop', 'sprint_id' => $sprint->id])->assertOk()->assertJsonPath('stopped', 0);
        $this->assertStringContainsString('Tidak ada eksekusi', ChatMessage::query()->where('role', 'assistant')->latest('id')->first()->content);
    }

    public function test_stop_button_endpoint_is_owner_only_and_button_shows_on_running_card(): void
    {
        ['user' => $user, 'run' => $run, 'story' => $story, 'sprint' => $sprint] = $this->setupRun();
        $command = ChatCommand::query()->create(['user_id' => $user->id, 'chat_conversation_id' => SprintChat::conversation($user, $sprint)->id, 'sprint_id' => $sprint->id, 'project_id' => $sprint->project_id, 'story_id' => $story->id, 'command' => 'perbaiki pagu', 'phase' => ChatCommand::CONFIRMED]);
        $command->conversation->messages()->create(['role' => 'user', 'kind' => 'command', 'content' => 'perbaiki pagu', 'chat_command_id' => $command->id]);

        $this->actingAs($user)->get(route('chat.index', ['project_id' => $sprint->project_id, 'sprint_id' => $sprint->id]))->assertOk()->assertSee('Hentikan paksa');

        $this->actingAs(User::factory()->create())->post(route('chat.commands.stop', $run))->assertNotFound();
        $this->assertSame('EXECUTING', $run->fresh()->status);

        $this->actingAs($user)->post(route('chat.commands.stop', $run))->assertRedirect()->assertSessionHas('status');
        $this->assertSame('FAILED', $run->fresh()->status);
        $this->actingAs($user)->post(route('chat.commands.stop', $run))->assertRedirect()->assertSessionHas('error');
    }

    public function test_reporter_tells_the_po_it_was_stopped_not_that_it_failed(): void
    {
        ['user' => $user, 'run' => $run, 'story' => $story, 'sprint' => $sprint] = $this->setupRun();
        ChatCommand::query()->create(['user_id' => $user->id, 'chat_conversation_id' => SprintChat::conversation($user, $sprint)->id, 'sprint_id' => $sprint->id, 'project_id' => $sprint->project_id, 'story_id' => $story->id, 'command' => 'x', 'phase' => ChatCommand::CONFIRMED]);

        app(ExecutionStopper::class)->stop($run);

        $report = ChatMessage::query()->where('execution_run_id', $run->id)->latest('id')->first();
        $this->assertStringContainsString('dihentikan paksa atas perintah Anda', $report->content);
        $this->assertStringNotContainsString('Perintah gagal dikerjakan', $report->content);
    }

    public function test_running_job_does_not_overwrite_a_stopped_run(): void
    {
        ['run' => $run, 'pending' => $pending] = $this->setupRun();
        $pending->forceFill(['status' => 'IN_PROGRESS'])->save();
        app(ExecutionStopper::class)->stop($run);

        $this->assertTrue(ExecutionStopper::wasStopped($run));
        // Job yang masuk setelah henti paksa tidak mengerjakan apa pun.
        (new ExecuteExecutionTaskJob($run->id, $pending->id))->handle(app(WorktreeManager::class), app(AgentToolRunner::class));
        $this->assertSame('FAILED', $run->fresh()->status);
        $this->assertSame(ExecutionStopper::CODE, $run->fresh()->error_code);
    }

    public function test_opencode_process_is_killed_when_stop_flag_is_set(): void
    {
        $dir = storage_path('framework/testing/oc-'.uniqid());
        mkdir($dir, 0700, true);
        $process = new Process(['sleep', '60'], $dir, null, null, 120);
        $process->start();

        Cache::put(ExecutionStopper::flagKey($dir), 1, 60);
        $wait = new \ReflectionMethod(OpenCodeRunner::class, 'waitWatchingQuota');
        $started = microtime(true);
        $ended = $wait->invoke(new OpenCodeRunner, $process, 'scrumgateway/x', $dir);

        $this->assertSame('stopped', $ended);
        $this->assertFalse($process->isRunning());
        $this->assertLessThan(15, microtime(true) - $started);

        // run() baru membersihkan penanda lama agar tidak membunuh pekerjaan berikutnya.
        ExecutionStopper::clearFlag($dir);
        $this->assertFalse(ExecutionStopper::stopRequested($dir));
    }
}
