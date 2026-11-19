<?php

namespace Tests;

use App\Models\Setting;
use App\Services\AntigravityClient;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\FakeAntigravityClient;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tes TIDAK BOLEH menghubungi layanan sungguhan. Suite dijalankan di VPS
        // produksi; permintaan tanpa fake langsung gagal di dalam tes.
        Http::preventStrayRequests();

        // Tes tidak pernah menjalankan proses agy (akun Google sungguhan).
        // Panggilan model diteruskan ke endpoint HTTP palsu yang di-fake tes.
        $this->app->instance(AntigravityClient::class, new FakeAntigravityClient);
    }

    /**
     * Pilih model untuk semua peran (dan opsional per peran), lalu fake
     * daftar model akun agar model itu dianggap tersedia.
     *
     * @param  array<string, string>  $roleModels
     */
    protected function useAiModel(string $model = 'gemini-3.8-flash-medium', array $roleModels = []): void
    {
        Setting::put('active_model', $model);
        foreach ($roleModels as $role => $roleModel) {
            Setting::put('role_model.'.$role, $roleModel);
        }
    }

    /**
     * Palsukan agen developer Antigravity: $work dipanggil dengan argumen
     * runTask (agent, worktree, prompt, model) dan boleh menulis file di
     * worktree seperti agen sungguhan. Kembalikan null untuk hasil sukses
     * standar, atau array hasil runTask sendiri; lempar exception untuk
     * mensimulasikan kegagalan.
     */
    protected function fakeDeveloper(\Closure $work, string $model = 'gemini-3.8-flash-medium'): MockInterface
    {
        $this->useAiModel($model);
        $mock = Mockery::mock(AntigravityClient::class);
        $mock->shouldReceive('modelIds')->andReturn([$model]);
        $mock->shouldReceive('runTask')->andReturnUsing(function (string $agent, string $worktree, string $prompt, string $runModel, int $timeout = 0, array $context = []) use ($work): array {
            return $work($agent, $worktree, $prompt, $runModel) ?? ['ok' => true, 'exit' => 0, 'output' => 'Selesai.', 'error' => null, 'usage' => []];
        });
        $this->app->instance(AntigravityClient::class, $mock);

        return $mock;
    }
}
