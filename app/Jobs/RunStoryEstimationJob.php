<?php

namespace App\Jobs;

use App\Models\StoryEstimationRun;
use App\Models\User;
use App\Services\StoryEstimationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RunStoryEstimationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 86400;

    public function __construct(public readonly int $runId, public readonly int $requesterId) {}

    public function uniqueId(): string
    {
        return 'story-estimation-run-'.$this->runId;
    }

    public function handle(StoryEstimationService $estimator): void
    {
        $run = StoryEstimationRun::query()->find($this->runId);
        $requester = User::query()->find($this->requesterId);
        if (! $run || ! $requester || $run->requested_by !== $requester->id) {
            return;
        }

        $estimator->estimateExisting($run, $requester);
    }

    public function failed(?Throwable $exception): void
    {
        $run = StoryEstimationRun::query()->find($this->runId);
        if (! $run || ! in_array($run->status, ['RUNNING', 'REPORT_PENDING'], true)) {
            return;
        }

        $run->forceFill(['status' => 'FAILED', 'completed_at' => now()])->save();
    }
}
