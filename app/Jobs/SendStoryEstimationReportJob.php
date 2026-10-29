<?php

namespace App\Jobs;

use App\Models\StoryEstimationRun;
use App\Services\StoryEstimationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
        } catch (Throwable $exception) {
            // Keep REPORT_PENDING so the existing report retry action can recover safely —
            // tapi kegagalan TIDAK BOLEH diam lagi (kasus Run 14: job DONE
            // tanpa jejak apa pun). Catat penyebabnya agar diagnosis berikutnya
            // tidak menebak-nebak.
            Log::warning('Story estimation report job failed', [
                'run_id' => $this->runId,
                'exception' => class_basename($exception),
                'message' => Str::limit($exception->getMessage(), 500),
            ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        // REPORT_PENDING is intentionally preserved for explicit report retry.
        Log::error('Story estimation report job exhausted', [
            'run_id' => $this->runId,
            'exception' => $exception !== null ? class_basename($exception) : null,
            'message' => $exception !== null ? Str::limit($exception->getMessage(), 500) : null,
        ]);
    }
}
