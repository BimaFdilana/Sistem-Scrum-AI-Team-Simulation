<?php

namespace App\Jobs;

use App\Models\Project;
use App\Support\Demo\DemoServerManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Setelah story diterima, demo yang sedang berjalan disegarkan (aset dan migrasi)
 * agar hasil yang baru disetujui langsung tampil. Di latar: build aset bisa memakan menit.
 */
class RefreshDemoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly int $projectId) {}

    public function handle(DemoServerManager $demos): void
    {
        $project = Project::query()->find($this->projectId);
        if ($project === null) {
            return;
        }

        try {
            $demos->refresh($project);
        } catch (Throwable $exception) {
            // Menyegarkan demo hanya kemudahan; penerimaan story sudah selesai dan tidak boleh terganggu.
            Log::warning('Demo gagal disegarkan setelah story diterima', ['project' => $this->projectId, 'error' => $exception->getMessage()]);
        }
    }
}
