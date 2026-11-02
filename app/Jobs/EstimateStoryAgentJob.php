<?php

namespace App\Jobs;

use App\Models\StoryEstimationAgentRun;
use App\Services\StoryEstimationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\TimeoutExceededException;
use Throwable;

class EstimateStoryAgentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 86400;

    public function __construct(public readonly int $runId, public readonly int $agentRunId)
    {
        // Vote lewat profile Hermes (CLI) bisa memakan waktu menit,
        // bukan detik seperti gateway — timeout job harus lebih besar
        // dari timeout perintah (600 detik) agar vote tidak dibunuh
        // di tengah jalan. Jalur gateway tetap 180 detik seperti dulu.
        if (config('ai.estimation_driver') === 'hermes') {
            $this->timeout = 900;
        }
    }

    public function uniqueId(): string
    {
        return 'story-estimation-agent-'.$this->agentRunId;
    }

    public function handle(StoryEstimationService $estimator): void
    {
        $entry = StoryEstimationAgentRun::query()->whereKey($this->agentRunId)
            ->where('story_estimation_run_id', $this->runId)->first();
        if (! $entry) {
            return;
        }
        $estimator->processAgent($entry);
        AdvanceStoryEstimationJob::dispatch($this->runId);
    }

    public function failed(?Throwable $exception): void
    {
        $entry = StoryEstimationAgentRun::query()->whereKey($this->agentRunId)
            ->where('story_estimation_run_id', $this->runId)->first();
        if (! $entry) {
            return;
        }
        app(StoryEstimationService::class)->failAgent($entry,
            $exception instanceof TimeoutExceededException ? 'WORKER_TIMEOUT' : 'WORKER_FAILED');
        AdvanceStoryEstimationJob::dispatch($this->runId);
    }
}
