<?php

namespace Tests\Feature;

use App\Jobs\BreakdownExecutionJob;
use App\Jobs\UnderstandChatCommandJob;
use App\Models\ChatCommand;
use App\Models\ChatMessage;
use App\Models\ExecutionRun;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Story;
use App\Models\User;
use App\Services\HermesClient;
use App\Support\Chat\ChatCommandReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery\MockInterface;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Tanya Scrum Master: SM memahami perintah dulu (judul + kriteria), PO mengonfirmasi
 * atau mengoreksi, baru tim bekerja. Kegagalan dijelaskan dan diberi tombol yang tepat.
 */
class ChatCommandUnderstandingTest extends TestCase
{
    use RefreshDatabase;

    private function sprint(): Sprint
    {
        $repo = storage_path('framework/testing/cmd-'.uniqid());
        mkdir($repo, 0700, true);
        (new Process(['git', 'init', '-q', '-b', 'main'], $repo))->mustRun();
        $project = Project::query()->create(['name' => 'SIPUA', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'BASELINE_READY', 'workspace_path' => $repo]);
        $backlog = $project->backlog()->firstOrCreate([], ['name' => 'B']);

        return Sprint::create(['backlog_id' => $backlog->id, 'project_id' => $project->id, 'name' => 'Sprint 2', 'status' => 'ACTIVE', 'revision' => 1]);
    }

    private function sendCommand(User $user, Sprint $sprint, string $text): ChatCommand
    {
        Bus::fake([UnderstandChatCommandJob::class, BreakdownExecutionJob::class]);
        $this->actingAs($user)->postJson(route('chat.commands.store'), ['message' => $text, 'sprint_id' => $sprint->id])->assertStatus(202);

        return ChatCommand::query()->latest('id')->firstOrFail();
    }

    private function smReplies(string $json): void
    {
        $this->mock(HermesClient::class, function (MockInterface $mock) use ($json): void {
            $mock->shouldReceive('sendMessage')->andReturn($json);
            $mock->shouldReceive('isAvailable')->andReturn(true);
        });
    }

    public function test_command_is_understood_first_and_team_waits_for_confirmation(): void
    {
        $user = User::factory()->create();
        $sprint = $this->sprint();
        $long = "Fix: pagu tampil Fleksibel.\nDescription:\nKetika admin menetapkan pagu...\nGiven pengusul login\nWhen membuka Usulan Saya\nThen pagu tampil\nTask 1 (Backend): periksa query\nTask 2 (Frontend): periksa blade";
        $command = $this->sendCommand($user, $sprint, $long);

        $this->assertSame(ChatCommand::UNDERSTANDING, $command->phase);
        $this->assertSame(0, ExecutionRun::query()->count()); // tim belum bekerja
        Bus::assertDispatched(UnderstandChatCommandJob::class);

        $this->smReplies('{"title":"Perbaiki tampilan pagu di Usulan Saya","criteria":["Pagu diambil dari unit pengusul yang login","Hanya pagu tahun anggaran aktif","Fleksibel hanya tampil bila pagu kosong"],"parts":[]}');
        app()->call([new UnderstandChatCommandJob($command->id), 'handle']);

        $command->refresh();
        $this->assertSame(ChatCommand::AWAITING, $command->phase);
        $this->assertCount(3, $command->criteria); // bukan 8 baris teks
        $this->actingAs($user)->get('/chat?sprint='.$sprint->id.'&tab=tanya')->assertOk()
            ->assertSee('RENCANA SCRUM MASTER')->assertSee('Perbaiki tampilan pagu di Usulan Saya')->assertSee('Sesuai, kerjakan');
    }

    public function test_po_can_edit_criteria_and_confirm_then_team_starts(): void
    {
        $user = User::factory()->create();
        $sprint = $this->sprint();
        $command = $this->sendCommand($user, $sprint, 'Tambah info pagu');
        $command->forceFill(['phase' => ChatCommand::AWAITING, 'title' => 'Tambah info pagu', 'criteria' => ['A', 'B']])->save();

        $this->actingAs($user)->post(route('chat.commands.confirm', $command), ['title' => 'Tampilkan pagu', 'criteria' => "1. Pagu tampil Rupiah\n2. Pagu sesuai unit\n- Pagu tahun aktif"])
            ->assertRedirect()->assertSessionHasNoErrors();

        $command->refresh();
        $story = Story::findOrFail($command->story_id);
        $this->assertSame(ChatCommand::CONFIRMED, $command->phase);
        $this->assertSame("Pagu tampil Rupiah\nPagu sesuai unit\nPagu tahun aktif", $story->acceptance_criteria);
        $this->assertSame('Tambah info pagu', $story->description); // perintah asli tetap jadi konteks
        $this->assertNull($story->sprint_id);
        $this->assertSame(1, ExecutionRun::query()->where('story_id', $story->id)->count());
        Bus::assertDispatched(BreakdownExecutionJob::class);
    }

    public function test_confirm_waits_while_team_is_busy_and_cancel_stops_the_plan(): void
    {
        $user = User::factory()->create();
        $sprint = $this->sprint();
        $busyStory = Story::create(['project_id' => $sprint->project_id, 'title' => 'Lain', 'estimate_points' => 3]);
        ExecutionRun::query()->create(['story_id' => $busyStory->id, 'requested_by' => $user->id, 'status' => 'EXECUTING', 'mode' => 'direct']);
        $command = $this->sendCommand($user, $sprint, 'Tambah info pagu');
        $command->forceFill(['phase' => ChatCommand::AWAITING, 'title' => 'T', 'criteria' => ['A']])->save();

        $this->actingAs($user)->post(route('chat.commands.confirm', $command), ['title' => 'T', 'criteria' => 'A'])->assertSessionHasErrors('command');
        $this->assertSame(ChatCommand::AWAITING, $command->fresh()->phase);

        $this->actingAs($user)->post(route('chat.commands.cancel', $command))->assertRedirect();
        $this->assertSame(ChatCommand::CANCELLED, $command->fresh()->phase);
    }

    public function test_big_command_is_split_into_parts_each_confirmed_separately(): void
    {
        $user = User::factory()->create();
        $sprint = $this->sprint();
        $command = $this->sendCommand($user, $sprint, 'Bangun modul laporan lengkap');
        $this->smReplies('{"title":"Bangun modul laporan","criteria":["x"],"parts":[{"title":"Halaman daftar laporan","criteria":["Daftar tampil"]},{"title":"Cetak PDF laporan","criteria":["PDF bisa diunduh"]}]}');

        app()->call([new UnderstandChatCommandJob($command->id), 'handle']);

        $parts = ChatCommand::query()->where('parent_id', $command->id)->orderBy('part_index')->get();
        $this->assertCount(2, $parts);
        $this->assertSame('Bagian 1 dari 2', $parts[0]->partLabel());
        $this->assertSame(ChatCommand::AWAITING, $parts[1]->phase);
        $this->actingAs($user)->get('/chat?sprint='.$sprint->id.'&tab=tanya')->assertOk()->assertSee('BAGIAN 2 DARI 2');
    }

    public function test_unparseable_answer_offers_try_again(): void
    {
        $user = User::factory()->create();
        $sprint = $this->sprint();
        $command = $this->sendCommand($user, $sprint, 'Ubah sesuatu');
        $this->smReplies('Maaf saya tidak paham');

        app()->call([new UnderstandChatCommandJob($command->id), 'handle']);

        $this->assertSame(ChatCommand::NOT_UNDERSTOOD, $command->fresh()->phase);
        $this->actingAs($user)->get('/chat?sprint='.$sprint->id.'&tab=tanya')->assertOk()->assertSee('Coba rumuskan lagi');
    }

    public function test_deterministic_failure_offers_rethink_and_transient_offers_rerun(): void
    {
        $user = User::factory()->create();
        $sprint = $this->sprint();
        $command = $this->sendCommand($user, $sprint, 'Tambah info pagu');
        $command->forceFill(['phase' => ChatCommand::AWAITING, 'title' => 'T', 'criteria' => ['A']])->save();
        $this->actingAs($user)->post(route('chat.commands.confirm', $command), ['title' => 'T', 'criteria' => 'A']);
        $run = ExecutionRun::query()->latest('id')->firstOrFail();

        $run->forceFill(['status' => 'FAILED', 'error_code' => 'BREAKDOWN_FAILED', 'summary' => 'Acceptance criteria story ada 12 butir'])->save();
        $this->assertSame('unclear', ChatCommandReporter::failureKind($run));
        $html = $this->actingAs($user)->get('/chat?sprint='.$sprint->id.'&tab=tanya')->getContent();
        $this->assertStringContainsString('Rumuskan ulang', $html);
        $this->assertStringContainsString('tidak bisa memetakan perintah', $html);

        $this->actingAs($user)->post(route('chat.commands.rethink', $command))->assertRedirect();
        $command->refresh();
        $this->assertSame(ChatCommand::UNDERSTANDING, $command->phase);
        $this->assertStringContainsString('12 butir', (string) $command->hint);

        $transient = ExecutionRun::query()->create(['story_id' => $run->story_id, 'requested_by' => $user->id, 'status' => 'FAILED', 'error_code' => 'EXECUTION_FAILED', 'summary' => 'Server AI sedang penuh', 'mode' => 'direct']);
        $this->assertSame('transient', ChatCommandReporter::failureKind($transient));
        $timeout = new ExecutionRun(['status' => 'FAILED', 'error_code' => 'EXECUTION_FAILED', 'summary' => 'The process exceeded the timeout of 1500 seconds']);
        $this->assertSame('too_big', ChatCommandReporter::failureKind($timeout));
    }

    public function test_reports_each_stage_once_and_offer_decision_buttons(): void
    {
        $user = User::factory()->create();
        $sprint = $this->sprint();
        $command = $this->sendCommand($user, $sprint, 'Ganti judul halaman');
        $command->forceFill(['phase' => ChatCommand::AWAITING, 'title' => 'Ganti judul', 'criteria' => ['Judul berubah']])->save();
        $this->actingAs($user)->post(route('chat.commands.confirm', $command), ['title' => 'Ganti judul', 'criteria' => 'Judul berubah']);
        $run = ExecutionRun::query()->latest('id')->firstOrFail();
        $run->tasks()->create(['title' => 'Ganti judul di layout', 'assignee' => 'frontend-developer', 'status' => 'PENDING', 'sort_order' => 0]);

        $run->forceFill(['status' => 'EXECUTING'])->save();
        $run->forceFill(['status' => 'EXECUTING', 'summary' => 'x'])->save();
        $run->forceFill(['status' => 'COMPLETED', 'summary' => 'Lulus review gabungan.'])->save();

        $reports = ChatMessage::query()->where('kind', 'report')->where('execution_run_id', $run->id)->where('content', 'not like', 'Dikonfirmasi%')->pluck('content');
        $this->assertCount(2, $reports);
        $this->assertStringContainsString('[FE] Ganti judul di layout', $reports[0]);

        $html = $this->actingAs($user)->get('/chat?sprint='.$sprint->id.'&tab=tanya')->getContent();
        $this->assertStringContainsString('✓ Terima', $html);
        $this->assertStringContainsString(route('stories.executions.accept', [$run->story_id, $run]), $html);
    }

    public function test_command_endpoints_are_private(): void
    {
        $owner = User::factory()->create();
        $sprint = $this->sprint();
        $command = $this->sendCommand($owner, $sprint, 'Ganti judul halaman');
        $command->forceFill(['phase' => ChatCommand::AWAITING, 'title' => 'T', 'criteria' => ['A']])->save();
        $other = User::factory()->create();

        $this->actingAs($owner)->getJson(route('chat.commands.phase', $command))->assertOk()->assertJsonPath('phase', ChatCommand::AWAITING);
        $this->actingAs($other)->getJson(route('chat.commands.phase', $command))->assertNotFound();
        $this->actingAs($other)->post(route('chat.commands.confirm', $command), ['title' => 'T', 'criteria' => 'A'])->assertForbidden();
    }
}
