<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\PublicGithubRepositoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ImportGithubProjectJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 86400;

    public function __construct(public readonly int $projectId) {}

    public function uniqueId(): string
    {
        return 'github-project-import-'.$this->projectId;
    }

    public function handle(PublicGithubRepositoryService $importer): void
    {
        $project = Project::query()->find($this->projectId);
        if (! $project || $project->status !== 'SOURCE_VERIFYING' || blank($project->source_repository_url)) {
            return;
        }

        try {
            $importer->import($project);
            $this->syncToGithub($project->fresh());
        } catch (Throwable $exception) {
            $project->forceFill([
                'status' => 'SOURCE_REJECTED',
                'source_error' => mb_substr($exception->getMessage(), 0, 255),
            ])->save();
            throw $exception;
        }
    }

    /**
     * Fase 2: pastikan repo GitHub PO (pakai yang sudah ada, atau fork).
     * Graceful: bila GitHub belum terhubung atau gagal, import tetap valid
     * (project jalan tanpa GitHub, seperti sebelum Fase 2).
     */
    protected function syncToGithub(Project $project): void
    {
        try {
            $service = app(\App\Services\GithubProjectService::class);
            if (! $service->isConnected()) {
                \Illuminate\Support\Facades\Log::info('GitHub belum terhubung — import dilewati tanpa fork', [
                    'project_id' => $project->id,
                ]);

                return;
            }

            $result = $service->ensureRepoForImport($project, (string) $project->source_repository_url);

            $project->forceFill([
                'github_owner' => $result['owner'],
                'github_repo' => $result['repo'],
            ])->save();
        } catch (Throwable $exception) {
            // Jangan gagalkan import — catat saja.
            \Illuminate\Support\Facades\Log::warning('Sinkronisasi GitHub import gagal (project tetap jalan tanpa GitHub)', [
                'project_id' => $project->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $project = Project::query()->find($this->projectId);
        if (! $project || $project->status === 'BASELINE_READY') {
            return;
        }

        $project->forceFill([
            'status' => 'SOURCE_REJECTED',
            'source_error' => mb_substr($exception?->getMessage() ?? 'Import GitHub gagal.', 0, 255),
        ])->save();
    }
}
