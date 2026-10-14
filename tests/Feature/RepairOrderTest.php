<?php

namespace Tests\Feature;

use App\Jobs\BreakdownExecutionJob;
use App\Models\ExecutionRun;
use App\Models\Project;
use App\Models\RepairOrder;
use App\Models\User;
use App\Services\HermesClient;
use App\Services\RepairOrderDetector;
use App\Support\Workspace\PatchApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class RepairOrderTest extends TestCase
{
    use RefreshDatabase;

    private function makeProject(string $name = 'Toko Online'): Project
    {
        return Project::query()->create([
            'name' => $name, 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel',
            'status' => 'BASELINE_READY', 'workspace_path' => storage_path('framework/testing'),
        ]);
    }

    private function fakeClassifier(array $result): HermesClient
    {
        $fake = Mockery::mock(HermesClient::class);
        $fake->shouldReceive('sendMessage')->andReturn(json_encode($result));

        return $fake;
    }

    public function test_heuristic_gates_ai_classification(): void
    {
        $detector = new RepairOrderDetector;
        $this->assertTrue($detector->looksLikeCommand('perbaiki halaman login yang lambat'));
        $this->assertTrue($detector->looksLikeCommand('Tolong tambahkan validasi email'));
        $this->assertFalse($detector->looksLikeCommand('kenapa login lambat?'));
        $this->assertFalse($detector->looksLikeCommand('halo, apa kabar?'));
    }

    public function test_chat_detects_repair_order_and_creates_draft(): void
    {
        $owner = User::factory()->create();
        $project = $this->makeProject();
        $this->app->instance(HermesClient::class, $this->fakeClassifier([
            'is_repair_order' => true,
            'title' => 'Perbaiki login lambat',
            'summary' => 'Optimalkan query login yang lambat.',
            'project_name' => null,
            'risky' => false,
            'risk_note' => null,
        ]));

        $response = $this->actingAs($owner)->postJson('/chat/messages', [
            'message' => 'perbaiki halaman login yang lambat',
        ]);

        $response->assertOk()->assertJsonPath('repair_order.title', 'Perbaiki login lambat');
        $orderId = $response->json('repair_order.id');
        $this->assertDatabaseHas('repair_orders', [
            'id' => $orderId, 'status' => 'DRAFT', 'project_id' => $project->id,
        ]);
        // Tidak ada yang dieksekusi otomatis dari chat.
        $this->assertDatabaseCount('execution_runs', 0);
    }

    public function test_chat_question_is_not_a_repair_order(): void
    {
        $owner = User::factory()->create();
        $this->makeProject();
        $fake = Mockery::mock(HermesClient::class);
        $fake->shouldReceive('sendMessage')->once()->andReturn('Login lambat biasanya karena query N+1.');
        $this->app->instance(HermesClient::class, $fake);

        $response = $this->actingAs($owner)->postJson('/chat/messages', [
            'message' => 'kenapa login lambat?',
        ]);

        $response->assertOk();
        $this->assertArrayNotHasKey('repair_order', $response->json());
        $this->assertDatabaseCount('repair_orders', 0);
    }

    public function test_ambiguous_project_requires_po_choice(): void
    {
        $owner = User::factory()->create();
        $this->makeProject('Toko Online');
        $this->makeProject('Kasir POS');
        $this->app->instance(HermesClient::class, $this->fakeClassifier([
            'is_repair_order' => true, 'title' => 'Perbaiki bug', 'summary' => 'Ada bug.',
            'project_name' => null, 'risky' => false, 'risk_note' => null,
        ]));

        $response = $this->actingAs($owner)->postJson('/chat/messages', ['message' => 'perbaiki bug itu']);
        $response->assertOk()->assertJsonPath('repair_order.needs_project', true);
        $orderId = $response->json('repair_order.id');
        $this->assertDatabaseHas('repair_orders', ['id' => $orderId, 'project_id' => null]);

        // Konfirmasi tanpa pilih project ditolak.
        $this->actingAs($owner)->postJson("/repair-orders/{$orderId}/confirm", [])
            ->assertStatus(422);

        $chosen = Project::query()->where('name', 'Kasir POS')->first();
        $this->actingAs($owner)->postJson("/repair-orders/{$orderId}/confirm", ['project_id' => $chosen->id])
            ->assertOk();
        $this->assertDatabaseHas('repair_orders', [
            'id' => $orderId, 'status' => 'CONFIRMED', 'project_id' => $chosen->id,
        ]);
    }

    public function test_confirm_and_cancel_from_chat_card(): void
    {
        $owner = User::factory()->create();
        $project = $this->makeProject();
        $order = RepairOrder::query()->create([
            'requested_by' => $owner->id, 'project_id' => $project->id,
            'title' => 'T', 'summary' => 'S', 'original_message' => 'perbaiki T', 'status' => 'DRAFT',
        ]);

        $this->actingAs($owner)->postJson("/repair-orders/{$order->id}/confirm", [])
            ->assertOk()->assertJsonStructure(['redirect_url']);
        $this->assertSame('CONFIRMED', $order->fresh()->status);

        $order2 = RepairOrder::query()->create([
            'requested_by' => $owner->id, 'project_id' => $project->id,
            'title' => 'T2', 'summary' => 'S2', 'original_message' => 'perbaiki T2', 'status' => 'DRAFT',
        ]);
        $this->actingAs($owner)->postJson("/repair-orders/{$order2->id}/cancel", [])->assertOk();
        $this->assertSame('CANCELLED', $order2->fresh()->status);
    }

    public function test_run_creates_repair_execution_run_and_breakdown_works(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $project = $this->makeProject();
        $order = RepairOrder::query()->create([
            'requested_by' => $owner->id, 'project_id' => $project->id,
            'title' => 'Perbaiki login', 'summary' => 'Optimalkan query.',
            'original_message' => 'perbaiki login', 'status' => 'CONFIRMED',
        ]);

        $this->actingAs($owner)->post("/repair-orders/{$order->id}/run")->assertRedirect();
        $this->assertDatabaseHas('execution_runs', [
            'repair_order_id' => $order->id, 'story_id' => null, 'status' => 'QUEUED',
        ]);
        $this->assertSame('RUNNING', $order->fresh()->status);
        Queue::assertPushed(BreakdownExecutionJob::class);

        // Breakdown untuk perintah perbaikan menghasilkan task + izin.
        $fake = Mockery::mock(HermesClient::class);
        $fake->shouldReceive('sendMessage')->once()->andReturn(json_encode([
            'tasks' => [
                ['title' => 'Optimalkan query', 'description' => 'Tambah index.', 'assignee' => 'backend-developer', 'files' => []],
            ],
        ]));
        $this->app->instance(HermesClient::class, $fake);

        $run = ExecutionRun::query()->where('repair_order_id', $order->id)->firstOrFail();
        (new BreakdownExecutionJob($run->id))->handle($fake, app(\App\Services\ChatDisclosurePolicy::class));

        $this->assertSame('AWAITING_PERMISSION', $run->fresh()->status);
        $this->assertSame(1, $run->tasks()->count());
        $this->assertDatabaseHas('permission_requests', ['execution_run_id' => $run->id, 'status' => 'PENDING']);
    }

    public function test_terminal_run_syncs_repair_order_status(): void
    {
        $owner = User::factory()->create();
        $project = $this->makeProject();
        $order = RepairOrder::query()->create([
            'requested_by' => $owner->id, 'project_id' => $project->id,
            'title' => 'T', 'summary' => 'S', 'original_message' => 'perbaiki T', 'status' => 'RUNNING',
        ]);
        $run = ExecutionRun::query()->create([
            'story_id' => null, 'repair_order_id' => $order->id,
            'requested_by' => $owner->id, 'status' => 'MERGING',
        ]);

        $run->forceFill(['status' => 'COMPLETED', 'completed_at' => now()])->save();
        $this->assertSame('COMPLETED', $order->fresh()->status);
    }

    public function test_patch_applier_blocks_delete_in_repair_mode(): void
    {
        $dir = storage_path('framework/testing/patch-'.uniqid());
        mkdir($dir, 0700, true);
        file_put_contents($dir.'/old.php', 'x');
        $patcher = new PatchApplier;

        try {
            $patcher->apply($dir, [['path' => 'old.php', 'action' => 'delete']], false);
            $this->fail('Delete seharusnya ditolak pada mode perintah perbaikan.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('tidak diizinkan', $e->getMessage());
        }
        $this->assertFileExists($dir.'/old.php');

        // Mode story tetap boleh (default true).
        $patcher->apply($dir, [['path' => 'old.php', 'action' => 'delete']]);
        $this->assertFileDoesNotExist($dir.'/old.php');
    }

    public function test_accept_locks_rerun(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $project = $this->makeProject();
        $order = RepairOrder::query()->create([
            'requested_by' => $owner->id, 'project_id' => $project->id,
            'title' => 'T', 'summary' => 'S', 'original_message' => 'perbaiki T', 'status' => 'RUNNING',
        ]);
        $run = ExecutionRun::query()->create([
            'story_id' => null, 'repair_order_id' => $order->id,
            'requested_by' => $owner->id, 'status' => 'COMPLETED',
        ]);

        $this->actingAs($owner)->post("/repair-orders/{$order->id}/executions/{$run->id}/accept", ['status' => 'BERHASIL'])
            ->assertRedirect();
        $this->assertSame('BERHASIL', $order->fresh()->acceptance_status);

        // Jalankan lagi dikunci.
        $order->forceFill(['status' => 'CONFIRMED'])->save();
        $this->actingAs($owner)->post("/repair-orders/{$order->id}/run")
            ->assertSessionHasErrors('execution');
    }

    public function test_retry_failed_order_back_to_confirmed_and_can_run_again(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $project = $this->makeProject();
        $order = RepairOrder::query()->create([
            'requested_by' => $owner->id, 'project_id' => $project->id,
            'title' => 'T', 'summary' => 'S', 'original_message' => 'perbaiki T', 'status' => 'FAILED',
        ]);
        ExecutionRun::query()->create([
            'story_id' => null, 'repair_order_id' => $order->id,
            'requested_by' => $owner->id, 'status' => 'FAILED',
        ]);

        // Coba lagi mengembalikan ke CONFIRMED.
        $this->actingAs($owner)->post("/repair-orders/{$order->id}/retry")->assertRedirect();
        $this->assertSame('CONFIRMED', $order->fresh()->status);

        // Bisa dijalankan lagi seperti biasa.
        $this->actingAs($owner)->post("/repair-orders/{$order->id}/run")->assertRedirect();
        $this->assertSame('RUNNING', $order->fresh()->status);
        $this->assertSame(2, ExecutionRun::query()->where('repair_order_id', $order->id)->count());
        Queue::assertPushed(BreakdownExecutionJob::class);
    }

    public function test_retry_blocked_order(): void
    {
        $owner = User::factory()->create();
        $project = $this->makeProject();
        $order = RepairOrder::query()->create([
            'requested_by' => $owner->id, 'project_id' => $project->id,
            'title' => 'T', 'summary' => 'S', 'original_message' => 'perbaiki T', 'status' => 'BLOCKED',
        ]);

        $this->actingAs($owner)->post("/repair-orders/{$order->id}/retry")->assertRedirect();
        $this->assertSame('CONFIRMED', $order->fresh()->status);
    }

    public function test_retry_rejected_unless_failed_or_blocked(): void
    {
        $owner = User::factory()->create();
        $project = $this->makeProject();
        $make = fn (string $status) => RepairOrder::query()->create([
            'requested_by' => $owner->id, 'project_id' => $project->id,
            'title' => 'T', 'summary' => 'S', 'original_message' => 'perbaiki T', 'status' => $status,
        ]);

        foreach (['DRAFT', 'CONFIRMED', 'RUNNING', 'COMPLETED', 'CANCELLED'] as $status) {
            $order = $make($status);
            $this->actingAs($owner)->post("/repair-orders/{$order->id}/retry")
                ->assertSessionHasErrors('execution');
            $this->assertSame($status, $order->fresh()->status);
        }
    }

    public function test_retry_rejected_when_already_accepted(): void
    {
        $owner = User::factory()->create();
        $project = $this->makeProject();
        $order = RepairOrder::query()->create([
            'requested_by' => $owner->id, 'project_id' => $project->id,
            'title' => 'T', 'summary' => 'S', 'original_message' => 'perbaiki T', 'status' => 'FAILED',
            'acceptance_status' => 'GAGAL', 'accepted_at' => now(),
        ]);

        $this->actingAs($owner)->post("/repair-orders/{$order->id}/retry")
            ->assertSessionHasErrors('execution');
        $this->assertSame('FAILED', $order->fresh()->status);
    }

    public function test_retry_rejected_while_run_active(): void
    {
        $owner = User::factory()->create();
        $project = $this->makeProject();
        $order = RepairOrder::query()->create([
            'requested_by' => $owner->id, 'project_id' => $project->id,
            'title' => 'T', 'summary' => 'S', 'original_message' => 'perbaiki T', 'status' => 'BLOCKED',
        ]);
        ExecutionRun::query()->create([
            'story_id' => null, 'repair_order_id' => $order->id,
            'requested_by' => $owner->id, 'status' => 'EXECUTING',
        ]);

        $this->actingAs($owner)->post("/repair-orders/{$order->id}/retry")
            ->assertSessionHasErrors('execution');
        $this->assertSame('BLOCKED', $order->fresh()->status);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
