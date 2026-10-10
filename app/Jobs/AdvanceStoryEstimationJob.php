<?php

namespace App\Jobs;

use App\Models\StoryEstimationRun;
use App\Services\StoryEstimationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class AdvanceStoryEstimationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public readonly int $runId) {}

    public function handle(StoryEstimationService $estimator): void
    {
        $run = StoryEstimationRun::query()->find($this->runId);
        if ($run) {
            $estimator->advance($run);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = StoryEstimationRun::query()->find($this->runId);
        if ($run && in_array($run->status, ['QUEUED', 'RUNNING'], true)) {
            $run->forceFill(['status' => 'FAILED', 'completed_at' => now()])->save();
        }
    }
}
