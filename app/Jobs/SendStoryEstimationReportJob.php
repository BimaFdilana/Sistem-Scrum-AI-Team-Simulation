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

class SendStoryEstimationReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $runId) {}

    public function handle(StoryEstimationService $estimator): void
    {
        $run = StoryEstimationRun::query()->with('requester')->find($this->runId);
        if (! $run || $run->status !== 'REPORT_PENDING' || ! $run->requester) {
            return;
        }

        try {
            $estimator->report($run, $run->requester);
        } catch (Throwable) {
            // Keep REPORT_PENDING so the existing report retry action can recover safely.
        }
    }

    public function failed(?Throwable $exception): void
    {
        // REPORT_PENDING is intentionally preserved for explicit report retry.
    }
}
