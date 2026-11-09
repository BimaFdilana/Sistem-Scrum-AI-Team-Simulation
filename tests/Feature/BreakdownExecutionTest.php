<?php

namespace Tests\Feature;

use App\Jobs\BreakdownExecutionJob;
use App\Models\ExecutionRun;
use App\Models\PermissionRequest;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use App\Services\AgentConfiguration;
use App\Services\ChatDisclosurePolicy;
use App\Services\CliProxyApiClient;
use App\Services\HermesClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class BreakdownExecutionTest extends TestCase
{
    use RefreshDatabase;

    private array $tempWorkspaces = [];

    protected function tearDown(): void
    {
        foreach ($this->tempWorkspaces as $root) {
            $this->deleteDirectory($root);
        }
        $this->tempWorkspaces = [];

        parent::tearDown();
    }

    private function deleteDirectory(string $root): void
    {
        if (! is_dir($root)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($root);
    }

    /** Buat workspace project tiruan berisi file-file nyata. */
    private function workspaceWith(array $files): string
    {
        $root = storage_path('framework/testing/inventory-'.uniqid('', true));

        foreach ($files as $relative) {
            $path = $root.'/'.$relative;
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, "<?php // fixture\n");
        }

        $this->tempWorkspaces[] = $root;

        return $root;
    }

    private function makeRun(?string $workspace = null): ExecutionRun
    {
        $owner = User::factory()->create();
        $project = Project::query()->create([
            'name' => 'Estimasi',
            'project_type' => 'NEW_PROJECT',
            'stack' => 'Laravel',
            'status' => 'SETUP_REQUIRED',
            'workspace_path' => $workspace,
        ]);
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
        return $this->withCriteria(['tasks' => [
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
        $run = $this->makeRun();
        $fake = Mockery::mock(HermesClient::class);
        // Satu percobaan awal + dua kesempatan perbaikan; ketiganya menolak.
        $fake->shouldReceive('sendMessage')->times(3)->andReturn(ChatDisclosurePolicy::SAFE_RESPONSE);

        try {
            (new BreakdownExecutionJob($run->id))->handle($fake, app(ChatDisclosurePolicy::class));
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
        $config = Mockery::mock(AgentConfiguration::class);
        $config->shouldReceive('current')->with('scrum-master')->andReturn(['status' => 'verified', 'model' => 'gemini-test']);
        $this->app->instance(AgentConfiguration::class, $config);
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

    public function test_breakdown_prompt_lists_real_files_and_repairs_invented_paths(): void
    {
        $root = $this->workspaceWith([
            'app/Http/Controllers/TransaksiController.php',
            'resources/views/modul_admin/transaksi/index.blade.php',
        ]);
        $run = $this->makeRun($root);

        $broken = $this->withCriteria(['tasks' => [[
            'title' => 'Tambahkan tanggal pada daftar pesanan',
            'description' => 'KERJAKAN: ubah controller. FILE: app/Http/Controllers/OrderController.php. HASIL: daftar menampilkan tanggal. SELESAI BILA: setiap baris menampilkan tanggal.',
            'assignee' => 'backend-developer',
            'files' => ['app/Http/Controllers/OrderController.php'],
        ]]]);

        $fixed = $this->withCriteria(['tasks' => [[
            'title' => 'Tambahkan tanggal pada daftar pesanan',
            'description' => 'KERJAKAN: kirim tanggal terformat dari controller dan render di view. FILE: app/Http/Controllers/TransaksiController.php, resources/views/modul_admin/transaksi/index.blade.php. HASIL: daftar menampilkan tanggal. SELESAI BILA: setiap baris menampilkan tanggal.',
            'assignee' => 'backend-developer',
            'files' => [
                'app/Http/Controllers/TransaksiController.php',
                'resources/views/modul_admin/transaksi/index.blade.php',
            ],
        ]]]);

        $prompts = [];
        $fake = Mockery::mock(HermesClient::class);
        $fake->shouldReceive('sendMessage')->twice()->andReturnUsing(function (string $prompt) use (&$prompts, $broken, $fixed) {
            $prompts[] = $prompt;

            return count($prompts) === 1 ? $broken : $fixed;
        });

        (new BreakdownExecutionJob($run->id))->handle($fake, app(ChatDisclosurePolicy::class));

        $run = $run->fresh();
        $this->assertSame('AWAITING_PERMISSION', $run->status);
        $this->assertStringContainsString('STRUKTUR PROJECT NYATA', $prompts[0]);
        $this->assertStringContainsString('app/Http/Controllers/TransaksiController.php', $prompts[0]);
        $this->assertStringContainsString('resources/views/modul_admin/transaksi/: index.blade.php', $prompts[0]);
        $this->assertStringContainsString('JAWABAN SEBELUMNYA DITOLAK', $prompts[1]);

        $payload = PermissionRequest::query()->latest('id')->firstOrFail()->payload;
        $this->assertSame([
            'app/Http/Controllers/TransaksiController.php',
            'resources/views/modul_admin/transaksi/index.blade.php',
        ], $payload['tasks'][0]['files']);
    }

    public function test_breakdown_fails_when_repair_still_uses_unknown_paths(): void
    {
        $root = $this->workspaceWith(['app/Http/Controllers/TransaksiController.php']);
        $run = $this->makeRun($root);

        $bad = $this->withCriteria(['tasks' => [[
            'title' => 'Tambahkan tanggal pada daftar pesanan',
            'description' => 'KERJAKAN: ubah controller. FILE: app/Http/Controllers/OrderController.php. HASIL: data tersedia. SELESAI BILA: field tanggal terisi.',
            'assignee' => 'backend-developer',
            'files' => ['app/Http/Controllers/OrderController.php'],
        ]]]);

        $fake = Mockery::mock(HermesClient::class);
        $fake->shouldReceive('sendMessage')->times(3)->andReturn($bad, $bad, $bad);

        try {
            (new BreakdownExecutionJob($run->id))->handle($fake, app(ChatDisclosurePolicy::class));
            $this->fail('Breakdown dengan path karangan harus gagal setelah satu perbaikan.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('tidak ada di project', $exception->getMessage());
        }

        $run = $run->fresh();
        $this->assertSame('FAILED', $run->status);
        $this->assertSame('BREAKDOWN_FAILED', $run->error_code);
        $this->assertSame(0, $run->tasks()->count());
    }

    public function test_breakdown_with_real_files_does_not_trigger_repair(): void
    {
        $root = $this->workspaceWith(['app/Http/Controllers/TransaksiController.php']);
        $run = $this->makeRun($root);

        $answers = $this->withCriteria(['tasks' => [[
            'title' => 'Kirim tanggal terformat dari controller',
            'description' => 'KERJAKAN: pakai helper tanggal di controller. FILE: app/Http/Controllers/TransaksiController.php. HASIL: setiap baris membawa field tanggal terformat. SELESAI BILA: field tanggal terisi untuk data valid.',
            'assignee' => 'backend-developer',
            'files' => ['app/Http/Controllers/TransaksiController.php'],
        ]]]);

        $fake = Mockery::mock(HermesClient::class);
        $fake->shouldReceive('sendMessage')->once()->andReturn($answers);

        (new BreakdownExecutionJob($run->id))->handle($fake, app(ChatDisclosurePolicy::class));

        $this->assertSame('AWAITING_PERMISSION', $run->fresh()->status);
    }

    public function test_breakdown_repairs_answer_missing_required_fields(): void
    {
        // Kasus nyata (3 Nov 2026, deepseek): SM membalas task tanpa
        // assignee/files, padahal sebelumnya struktur itu wajib.
        $root = $this->workspaceWith(['resources/views/customer/transaksi/index.blade.php']);
        $run = $this->makeRun($root);

        $incomplete = $this->withCriteria(['tasks' => [[
            'title' => 'Tambahkan kolom tanggal pada daftar pesanan customer',
            'description' => 'KERJAKAN: tambah kolom. FILE: resources/views/customer/transaksi/index.blade.php. HASIL: tanggal tampil. SELESAI BILA: tanggal tampil.',
        ]]]);

        $complete = $this->withCriteria(['tasks' => [[
            'title' => 'Tambahkan kolom tanggal pada daftar pesanan customer',
            'description' => 'KERJAKAN: tambah kolom. FILE: resources/views/customer/transaksi/index.blade.php. HASIL: tanggal tampil. SELESAI BILA: tanggal tampil.',
            'assignee' => 'frontend-developer',
            'files' => ['resources/views/customer/transaksi/index.blade.php'],
        ]]]);

        $prompts = [];
        $fake = Mockery::mock(HermesClient::class);
        $fake->shouldReceive('sendMessage')->twice()->andReturnUsing(function (string $prompt) use (&$prompts, $incomplete, $complete) {
            $prompts[] = $prompt;

            return count($prompts) === 1 ? $incomplete : $complete;
        });

        (new BreakdownExecutionJob($run->id))->handle($fake, app(ChatDisclosurePolicy::class));

        $this->assertSame('AWAITING_PERMISSION', $run->fresh()->status);
        $this->assertStringContainsString('KEEMPAT field', $prompts[1]);
        $this->assertSame(1, $run->tasks()->count());
    }

    private function oneTask(): array
    {
        return [[
            'title' => 'Kirim tanggal terformat dari controller',
            'description' => 'KERJAKAN: pakai helper tanggal. FILE: app/Http/Controllers/TransaksiController.php. HASIL: field tanggal. SELESAI BILA: field tanggal terisi.',
            'assignee' => 'backend-developer',
            'files' => ['app/Http/Controllers/TransaksiController.php'],
        ]];
    }

    public function test_missing_criteria_mapping_is_repaired_and_coverage_is_stored(): void
    {
        $root = $this->workspaceWith(['app/Http/Controllers/TransaksiController.php']);
        $run = $this->makeRun($root);
        $noCriteria = json_encode(['tasks' => $this->oneTask()]);
        $complete = json_encode(['tasks' => $this->oneTask(), 'criteria' => [
            ['criterion' => 'Tombol muncul setelah scroll.', 'tasks' => [1]],
            ['criterion' => 'Klik menggulir halus ke atas.', 'tasks' => [1]],
        ]]);

        $prompts = [];
        $fake = Mockery::mock(HermesClient::class);
        $fake->shouldReceive('sendMessage')->twice()->andReturnUsing(function (string $prompt) use (&$prompts, $noCriteria, $complete) {
            $prompts[] = $prompt;

            return count($prompts) === 1 ? $noCriteria : $complete;
        });

        (new BreakdownExecutionJob($run->id))->handle($fake, app(ChatDisclosurePolicy::class));

        $this->assertSame('AWAITING_PERMISSION', $run->fresh()->status);
        $this->assertStringContainsString('Field "criteria" hilang', $prompts[1]);
        $payload = PermissionRequest::query()->latest('id')->firstOrFail()->payload;
        $this->assertCount(2, $payload['coverage']);
        $this->assertSame([1], $payload['coverage'][0]['tasks']);
    }

    public function test_plan_with_task_serving_no_criterion_is_rejected(): void
    {
        $root = $this->workspaceWith(['app/Http/Controllers/TransaksiController.php']);
        $run = $this->makeRun($root);
        $tasks = array_merge($this->oneTask(), [[
            'title' => 'Ubah hal lain yang tidak diminta',
            'description' => 'KERJAKAN: ubah. FILE: app/Http/Controllers/TransaksiController.php. HASIL: x. SELESAI BILA: x.',
            'assignee' => 'backend-developer',
            'files' => ['app/Http/Controllers/TransaksiController.php'],
        ]]);
        $orphan = json_encode(['tasks' => $tasks, 'criteria' => [
            ['criterion' => 'Tombol muncul setelah scroll.', 'tasks' => [1]],
            ['criterion' => 'Klik menggulir halus ke atas.', 'tasks' => [1]],
        ]]);

        $fake = Mockery::mock(HermesClient::class);
        $fake->shouldReceive('sendMessage')->times(3)->andReturn($orphan);

        try {
            (new BreakdownExecutionJob($run->id))->handle($fake, app(ChatDisclosurePolicy::class));
            $this->fail('Task yang tidak melayani criteria mana pun harus ditolak.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('Task nomor 2', $exception->getMessage());
        }

        $this->assertSame('FAILED', $run->fresh()->status);
    }

    public function test_plan_mapping_fewer_criteria_than_story_has_is_rejected(): void
    {
        $root = $this->workspaceWith(['app/Http/Controllers/TransaksiController.php']);
        $run = $this->makeRun($root);
        $partial = json_encode(['tasks' => $this->oneTask(), 'criteria' => [
            ['criterion' => 'Tombol muncul setelah scroll.', 'tasks' => [1]],
        ]]);

        $fake = Mockery::mock(HermesClient::class);
        $fake->shouldReceive('sendMessage')->times(3)->andReturn($partial);

        try {
            (new BreakdownExecutionJob($run->id))->handle($fake, app(ChatDisclosurePolicy::class));
            $this->fail('Pemetaan yang melewatkan butir criteria harus ditolak.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('hanya memuat 1', $exception->getMessage());
        }
    }

    /** JSON breakdown lengkap: tiap criteria dipetakan ke semua task. */
    private function withCriteria(array $data): string
    {
        $ids = $data['tasks'] === [] ? [1] : range(1, count($data['tasks']));
        $data['criteria'] = [
            ['criterion' => 'Kriteria 1', 'tasks' => $ids],
            ['criterion' => 'Kriteria 2', 'tasks' => $ids],
            ['criterion' => 'Kriteria 3', 'tasks' => $ids],
        ];

        return json_encode($data);
    }
}
