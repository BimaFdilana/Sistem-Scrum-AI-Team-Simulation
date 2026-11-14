<?php

namespace Tests\Feature;

use App\Jobs\ExecuteExecutionTaskJob;
use App\Models\ExecutionRun;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use App\Services\AgentToolRunner;
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

    private function runJob(ExecutionRun $run, $task, $runner): void
    {
        $worktrees = new WorktreeManager;
        (new ExecuteExecutionTaskJob($run->id, $task->id))->handle(
            $worktrees,
            $runner,
        );
    }

    public function test_tool_loop_berhasil_tanpa_notifikasi(): void
    {
        Queue::fake();
        [$owner, $baseline, $run, $task] = $this->makeRun();

        $runner = $this->mock(AgentToolRunner::class, function ($mock): void {
            $mock->shouldReceive('run')->once()->andReturnUsing(function (string $slug, string $worktree) {
                mkdir($worktree.'/app', 0755, true);
                file_put_contents($worktree.'/app/Ok.php', '<?php // ok');

                return ['files' => ['app/Ok.php'], 'summary' => 'Tambah file ok'];
            });
        });

        $this->runJob($run, $task, $runner);

        $this->assertSame('IN_REVIEW', $task->fresh()->status);
        $this->assertSame('REVIEWING', $run->fresh()->status);
        $this->assertStringContainsString('app/Ok.php', $task->fresh()->diff_snapshot ?? '', 'Diff hasil host harus tersimpan per task.');
        $this->assertSame(0, Notification::query()->count(), 'Sukses tidak boleh buat notifikasi.');
        $worktree = $task->fresh()->worktree_path;
        $this->assertNotEmpty($worktree);
        $this->assertFileExists($worktree.'/app/Ok.php');
    }

    public function test_tool_loop_gagal_membuat_notifikasi_dan_fail(): void
    {
        [$owner, $baseline, $run, $task] = $this->makeRun();

        $runner = $this->mock(AgentToolRunner::class, function ($mock): void {
            $mock->shouldReceive('run')->once()->andThrow(new \RuntimeException('Agent gagal.'));
        });

        try {
            $this->runJob($run, $task, $runner);
            $this->fail('Job seharusnya melempar ulang exception.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Agent gagal.', $e->getMessage());
        }

        $this->assertSame('FAILED', $task->fresh()->status);
        $this->assertSame('FAILED', $run->fresh()->status);

        $notif = Notification::query()->where('user_id', $owner->id)->first();
        $this->assertNotNull($notif, 'Notifikasi harus terbuat untuk PO.');
        $this->assertSame('patch_failed', $notif->type);
        $this->assertStringContainsString("task #{$task->id}", $notif->message);
        $this->assertStringContainsString('Eksekusi dihentikan', $notif->message);
        $this->assertStringContainsString("/stories/{$run->story_id}/executions/{$run->id}", $notif->link);
    }

    public function test_runner_gagal_sekali_tidak_retry_tanpa_batas(): void
    {
        [$owner, $baseline, $run, $task] = $this->makeRun();

        $runner = $this->mock(AgentToolRunner::class, function ($mock): void {
            $mock->shouldReceive('run')->once()->andThrow(new \RuntimeException('Agent gagal.'));
        });

        try {
            $this->runJob($run, $task, $runner);
            $this->fail('Job seharusnya melempar ulang exception.');
        } catch (\RuntimeException) {
            // diharapkan
        }

        $this->assertSame(1, Notification::query()->where('user_id', $owner->id)->count());
    }

    public function test_write_error_langsung_fail_tapi_notifikasi(): void
    {
        [$owner, $baseline, $run, $task] = $this->makeRun();

        $runner = $this->mock(AgentToolRunner::class, function ($mock): void {
            $mock->shouldReceive('run')->once()->andThrow(new \RuntimeException('gagal menulis file.'));
        });

        try {
            $this->runJob($run, $task, $runner);
            $this->fail('Job seharusnya melempar ulang exception.');
        } catch (\RuntimeException $e) {
            $this->assertSame('gagal menulis file.', $e->getMessage());
        }

        $this->assertSame('FAILED', $task->fresh()->status);
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
        $this->actingAs($owner)->get('/dashboard')->assertSee('topbar-bell-count'); // lonceng notifikasi di topbar

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

    public function test_ai_error_juga_membuat_notifikasi(): void
    {
        [$owner, $baseline, $run, $task] = $this->makeRun();

        $runner = $this->mock(AgentToolRunner::class, function ($mock): void {
            $mock->shouldReceive('run')->andThrow(new \RuntimeException('AI timeout'));
        });

        try {
            $this->runJob($run, $task, $runner);
            $this->fail('Seharusnya throw');
        } catch (\RuntimeException) {
        }

        $this->assertEquals('FAILED', $task->fresh()->status);
        $notif = Notification::query()->where('user_id', $owner->id)->first();
        $this->assertNotNull($notif, 'PO harus dapat notifikasi saat AI error');
        $this->assertStringContainsString('AI timeout', $notif->message);
    }

    public function test_tool_loop_tidak_selesai_juga_membuat_notifikasi(): void
    {
        [$owner, $baseline, $run, $task] = $this->makeRun();

        $runner = $this->mock(AgentToolRunner::class, function ($mock): void {
            $mock->shouldReceive('run')->andThrow(new \RuntimeException('Agent tidak menyelesaikan task lewat tool.'));
        });

        try {
            $this->runJob($run, $task, $runner);
            $this->fail('Seharusnya throw');
        } catch (\Throwable) {
        }

        $this->assertEquals('FAILED', $task->fresh()->status);
        $this->assertNotNull(
            Notification::query()->where('user_id', $owner->id)->first(),
            'PO harus dapat notifikasi saat tool loop gagal'
        );
    }

    public function test_tidak_ada_duplikasi_notifikasi(): void
    {
        [$owner, $baseline, $run, $task] = $this->makeRun();

        $runner = $this->mock(AgentToolRunner::class, function ($mock): void {
            $mock->shouldReceive('run')->andThrow(new \RuntimeException('Agent gagal.'));
        });

        try {
            $this->runJob($run, $task, $runner);
            $this->fail('Seharusnya throw');
        } catch (\Throwable) {
        }

        $count = Notification::query()->where('user_id', $owner->id)->count();
        $this->assertEquals(1, $count, 'Hanya boleh ada 1 notifikasi, tidak duplikat');
    }
}
