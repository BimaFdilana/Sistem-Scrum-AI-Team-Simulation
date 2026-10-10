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
        } catch (Throwable $exception) {
            $project->forceFill([
                'status' => 'SOURCE_REJECTED',
                'source_error' => mb_substr($exception->getMessage(), 0, 255),
            ])->save();
            throw $exception;
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
