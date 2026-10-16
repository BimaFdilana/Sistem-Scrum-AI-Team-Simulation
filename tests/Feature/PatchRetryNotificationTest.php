<?php

namespace Tests\Feature;

use App\Jobs\ExecuteExecutionTaskJob;
use App\Models\ExecutionRun;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use App\Services\AgentProfileClient;
use App\Support\Workspace\PatchApplier;
use App\Support\Workspace\PatchValidationException;
use App\Support\Workspace\WorktreeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PatchRetryNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(array $taskOverrides = []): array
    {
        $baseline = storage_path('framework/testing/patch-retry-'.uniqid());
        mkdir($baseline, 0700, true);
        file_put_contents($baseline.'/README.md', 'baseline');

        $owner = User::factory()->create();
        $project = Project::query()->create([
            'name' => 'PR', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel',
            'status' => 'BASELINE_READY', 'workspace_path' => $baseline,
        ]);
        $story = Story::query()->create([
            'project_id' => $project->id, 'title' => 'Story S', 'estimate_points' => 5,
        ]);
        $run = ExecutionRun::query()->create([
            'story_id' => $story->id, 'requested_by' => $owner->id, 'status' => 'EXECUTING',
        ]);
        $task = $run->tasks()->create(array_merge([
            'title' => 'Task T', 'description' => 'Desc', 'assignee' => 'backend-developer', 'status' => 'PENDING',
        ], $taskOverrides));

        return [$owner, $baseline, $run, $task];
    }

    private function runJob(ExecutionRun $run, $task, $agents, $patcher = null): void
    {
        $worktrees = new WorktreeManager;
        (new ExecuteExecutionTaskJob($run->id, $task->id))->handle(
            $agents,
            $worktrees,
            $patcher ?? app(PatchApplier::class),
        );
    }

    private function validPatchJson(string $path = 'app/Ok.php'): string
    {
        return json_encode([
            'files' => [['path' => $path, 'action' => 'create', 'content' => '<?php // ok']],
            'summary' => 'Tambah file ok',
        ]);
    }

    private function invalidPatchJson(): string
    {
        return json_encode([
            'files' => [['path' => '../evil.php', 'action' => 'create', 'content' => 'x']],
            'summary' => 'Patch jahat',
        ]);
    }

    public function test_retry_berhasil_setelah_patch_kecil(): void
    {
        Queue::fake();
        [$owner, $baseline, $run, $task] = $this->makeRun();

        $agents = $this->mock(AgentProfileClient::class, function ($mock): void {
            $mock->shouldReceive('sendMessage')->twice()->andReturn(
                $this->invalidPatchJson(),   // percobaan pertama: ditolak validasi
                $this->validPatchJson(),     // retry: patch kecil valid
            );
        });

        $this->runJob($run, $task, $agents);

        $this->assertSame('IN_REVIEW', $task->fresh()->status);
        $this->assertSame('REVIEWING', $run->fresh()->status);
        $this->assertSame(1, $task->fresh()->patch_retry_count);
        $this->assertSame(0, Notification::query()->count(), 'Retry sukses tidak boleh buat notifikasi.');
        $worktree = $task->fresh()->worktree_path;
        $this->assertNotEmpty($worktree);
        $this->assertFileExists($worktree.'/app/Ok.php');
    }

    public function test_retry_gagal_membuat_notifikasi_dan_fail(): void
    {
        [$owner, $baseline, $run, $task] = $this->makeRun();

        $agents = $this->mock(AgentProfileClient::class, function ($mock): void {
            $mock->shouldReceive('sendMessage')->twice()->andReturn(
                $this->invalidPatchJson(),
                $this->invalidPatchJson(), // retry juga gagal
            );
        });

        try {
            $this->runJob($run, $task, $agents);
            $this->fail('Job seharusnya melempar ulang exception.');
        } catch (PatchValidationException $e) {
            $this->assertStringContainsString('path traversal', $e->getMessage());
        }

        $this->assertSame('FAILED', $task->fresh()->status);
        $this->assertSame('FAILED', $run->fresh()->status);
        $this->assertSame(1, $task->fresh()->patch_retry_count);

        $notif = Notification::query()->where('user_id', $owner->id)->first();
        $this->assertNotNull($notif, 'Notifikasi harus terbuat untuk PO.');
        $this->assertSame('patch_failed', $notif->type);
        $this->assertStringContainsString("task #{$task->id}", $notif->message);
        $this->assertStringContainsString('Eksekusi dihentikan', $notif->message);
        $this->assertStringContainsString("/stories/{$run->story_id}/executions/{$run->id}", $notif->link);
    }

    public function test_tidak_ada_infinite_retry(): void
    {
        [$owner, $baseline, $run, $task] = $this->makeRun(['patch_retry_count' => 1]);

        // AI hanya dipanggil sekali (percobaan awal); retry tidak boleh terjadi lagi.
        $agents = $this->mock(AgentProfileClient::class, function ($mock): void {
            $mock->shouldReceive('sendMessage')->once()->andReturn($this->invalidPatchJson());
        });

        try {
            $this->runJob($run, $task, $agents);
            $this->fail('Job seharusnya melempar ulang exception.');
        } catch (PatchValidationException) {
            // diharapkan
        }

        $this->assertSame(1, $task->fresh()->patch_retry_count, 'Retry count tidak boleh bertambah lagi.');
        $this->assertSame(1, Notification::query()->where('user_id', $owner->id)->count());
    }

    public function test_write_error_langsung_fail_tanpa_retry_tapi_notifikasi(): void
    {
        [$owner, $baseline, $run, $task] = $this->makeRun();

        $agents = $this->mock(AgentProfileClient::class, function ($mock): void {
            $mock->shouldReceive('sendMessage')->once()->andReturn($this->validPatchJson());
        });

        // Simulasi error tulis (disk penuh): bukan PatchValidationException.
        $patcher = $this->mock(PatchApplier::class, function ($mock): void {
            $mock->shouldReceive('apply')->once()->andThrow(new \RuntimeException('gagal menulis file.'));
        });

        try {
            $this->runJob($run, $task, $agents, $patcher);
            $this->fail('Job seharusnya melempar ulang exception.');
        } catch (\RuntimeException $e) {
            $this->assertSame('gagal menulis file.', $e->getMessage());
        }

        $this->assertSame('FAILED', $task->fresh()->status);
        $this->assertSame(0, $task->fresh()->patch_retry_count, 'Write error tidak boleh retry.');
        $this->assertSame(1, Notification::query()->where('user_id', $owner->id)->count());
    }

    public function test_halaman_notifikasi_dan_badge(): void
    {
        $owner = User::factory()->create();
        Notification::query()->create([
            'user_id' => $owner->id, 'type' => 'patch_failed',
            'title' => 'Patch task ditolak', 'message' => 'Pesan test.', 'link' => '/x',
        ]);

        // Badge muncul di sidebar.
        $this->actingAs($owner)->get('/dashboard')->assertSee('nav-badge');

        // Halaman daftar.
        $this->actingAs($owner)->get('/notifications')
            ->assertOk()
            ->assertSee('Patch task ditolak')
            ->assertSee('Pesan test.');

        // Tandai dibaca.
        $notif = Notification::query()->first();
        $this->actingAs($owner)->post("/notifications/{$notif->id}/read")->assertRedirect();
        $this->assertNotNull($notif->fresh()->read_at);

        // User lain tidak bisa tandai.
        $other = User::factory()->create();
        $notif2 = Notification::query()->create([
            'user_id' => $owner->id, 'type' => 'patch_failed',
            'title' => 'T', 'message' => 'M', 'link' => null,
        ]);
        $this->actingAs($other)->post("/notifications/{$notif2->id}/read")->assertForbidden();
    }

    public function test_guest_tidak_bisa_akses_notifikasi(): void
    {
        $this->get('/notifications')->assertRedirect('/login');
    }
}
