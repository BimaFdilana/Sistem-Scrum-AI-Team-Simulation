<?php

namespace App\Http\Controllers;

use App\Jobs\AdvanceStoryEstimationJob;
use App\Models\Story;
use App\Models\StoryEstimationAgentRun;
use App\Models\StoryEstimationRun;
use App\Services\StoryEstimationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class StoryEstimationController extends Controller
{
    public function store(Request $request, Story $story, StoryEstimationService $estimator): RedirectResponse|JsonResponse
    {
        if (! $this->schemaReady()) {
            return $this->backToBacklog($story)->withErrors(['estimation' => 'Diskusi Fibonacci menunggu migrasi database estimasi.']);
        }
        if (! $story->sprint_id || $story->sprint?->status !== 'PLANNING') {
            return $this->backToBacklog($story)->withErrors(['estimation' => 'Diskusi hanya dapat diminta untuk User Story pada Sprint PLANNING.']);
        }
        [$run, $created] = $estimator->claimRun($story->load('actor'), $request->user());
        if ($created) {
            AdvanceStoryEstimationJob::dispatch($run->id);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'run_id' => $run->id,
                'status' => $run->status,
                'status_url' => route('stories.estimations.status', [$story, $run]),
                'discussion_url' => route('stories.discussion.show', $story),
            ], $created ? 202 : 200);
        }

        return $this->backToBacklog($story)->with('status', $created
            ? 'Diskusi Fibonacci sedang diproses di latar belakang. Status run: QUEUED.'
            : 'Diskusi Fibonacci untuk revisi ini sudah berjalan atau selesai. Status run: '.$run->status.'.');
    }

    public function retry(Request $request, Story $story, StoryEstimationRun $run, StoryEstimationService $estimator): RedirectResponse
    {
        abort_unless((int) $run->story_id === (int) $story->id, 404);
        abort_unless($this->schemaReady(), 503);
        abort_unless((int) $run->requested_by === (int) $request->user()->id, 403);
        if (! in_array($run->status, ['FAILED', 'PARTIAL', 'NO_CONSENSUS'], true)
            || $run->story_revision !== $story->revision || $story->sprint?->status !== 'PLANNING') {
            return $this->backToBacklog($story)->withErrors(['estimation' => 'Run ini belum dapat diulang. Hanya FAILED, PARTIAL, atau NO_CONSENSUS pada revisi aktif yang dapat dicoba lagi.']);
        }

        [$retry, $created] = $estimator->claimRun($story->load('actor'), $request->user());
        if (! $created) {
            return $this->backToBacklog($story)->withErrors(['estimation' => 'Diskusi story ini masih berlangsung; retry tidak membuat run ganda.']);
        }
        AdvanceStoryEstimationJob::dispatch($retry->id);

        return $this->backToBacklog($story)->with('status', 'Retry Fibonacci dijadwalkan. Status run: RUNNING.');
    }

    public function retryAgent(Request $request, Story $story, StoryEstimationRun $run, StoryEstimationAgentRun $agentRun, StoryEstimationService $estimator): RedirectResponse
    {
        abort_unless((int) $run->story_id === (int) $story->id, 404);
        abort_unless($this->schemaReady(), 503);
        try {
            $estimator->retryAgent($run, $agentRun, $request->user());
        } catch (\RuntimeException) {
            return $this->backToBacklog($story)->withErrors(['estimation' => 'Agent gagal belum dapat diulang untuk revisi aktif ini.']);
        }

        return $this->backToBacklog($story)->with('status', 'Retry agent Fibonacci dijadwalkan.');
    }

    public function discussion(Request $request, Story $story): View
    {
        $ready = $this->schemaReady();

        $runs = $ready ? $story->estimationRuns()
            ->where('requested_by', $request->user()->id)
            ->with([
                'agentRuns',
                'messages' => fn ($query) => $query->where('recipient_slug', 'scrum-master'),
            ])->latest('created_at')->get() : collect();

        return view('stories.discussion', [
            'story' => $story->load('project', 'sprint'),
            'runs' => $runs,
            'currentRun' => $runs->first(fn ($run) => (int) $run->story_revision === (int) $story->revision) ?? $runs->first(),
            'executions' => \Illuminate\Support\Facades\Schema::hasTable('execution_runs')
                ? $story->executionRuns()->withCount('tasks')->latest()->get()
                : collect(),
            'schemaReady' => $ready,
            'activePage' => 'discussion',
        ]);
    }

    public function status(Request $request, Story $story, StoryEstimationRun $run): JsonResponse
    {
        abort_unless((int) $run->story_id === (int) $story->id, 404);
        abort_unless((int) $run->requested_by === (int) $request->user()->id, 403);

        $estimator = app(StoryEstimationService::class);
        $estimator->recoverStale($run);
        $run->load('agentRuns');
        $round = min(StoryEstimationService::MAX_DISCUSSION_ROUNDS, (int) ($run->agentRuns->max('round') ?: 1));
        $roundRuns = $run->agentRuns->where('round', $round);
        $failed = $run->agentRuns->where('status', 'FAILED')->sortByDesc('id')->first();
        $completed = $roundRuns->where('status', 'COMPLETED')->count();
        $running = $roundRuns->where('status', 'RUNNING')->sortByDesc('id')->first();

        return response()->json([
            'run_id' => $run->id,
            'status' => $run->status,
            'round' => $round,
            'total_rounds' => StoryEstimationService::MAX_DISCUSSION_ROUNDS,
            'completed_agents' => $completed,
            'total_agents' => 3,
            'active_profile' => $running?->profile_slug,
            'failed_agent' => $failed ? [
                'profile' => $failed->profile_slug,
                'round' => $failed->round,
                'error_code' => $failed->error_code,
            ] : null,
            'terminal' => in_array($run->status, ['COMPLETED', 'FAILED', 'PARTIAL', 'NO_CONSENSUS', 'STALE', 'CANCELLED'], true),
        ]);
    }

    public function report(Request $request, Story $story, StoryEstimationRun $run, StoryEstimationService $estimator): RedirectResponse
    {
        abort_unless((int) $run->story_id === (int) $story->id, 404);
        abort_unless($this->schemaReady(), 503);
        abort_unless((int) $run->requested_by === (int) $request->user()->id, 403);
        if ($run->status !== 'REPORT_PENDING' || $run->story_revision !== $story->revision || $story->sprint?->status !== 'PLANNING') {
            return $this->backToBacklog($story)->withErrors(['estimation' => 'Laporan tidak dapat diulang untuk run atau revisi ini.']);
        }

        try {
            $result = $estimator->report($run, $request->user());
        } catch (\RuntimeException) {
            return $this->backToBacklog($story)->withErrors(['estimation' => 'Bukti kesepakatan tidak tersedia atau status berubah. Muat ulang diskusi.']);
        }

        return $result->status === 'COMPLETED'
            ? $this->backToBacklog($story)->with('status', 'Laporan kesepakatan Scrum Master berhasil disimpan.')
            : $this->backToBacklog($story)->withErrors(['estimation' => 'Laporan belum tersimpan; poin final belum aktif.']);
    }

    public function cancel(Request $request, Story $story, StoryEstimationRun $run, StoryEstimationService $estimator): RedirectResponse
    {
        abort_unless((int) $run->story_id === (int) $story->id, 404);
        abort_unless($this->schemaReady(), 503);
        abort_unless((int) $run->requested_by === (int) $request->user()->id, 403);

        try {
            $cancelled = $estimator->cancelStaleRun($run, $request->user());
        } catch (\RuntimeException) {
            return $this->backToBacklog($story)->withErrors(['estimation' => 'Hanya run RUNNING yang melewati batas waktu aman dapat dibatalkan.']);
        }

        return $cancelled->status === 'CANCELLED'
            ? $this->backToBacklog($story)->with('status', 'Run lama dihentikan; diskusi baru dapat diminta. Riwayat tetap tersimpan.')
            : $this->backToBacklog($story)->withErrors(['estimation' => 'Run belum dapat dibatalkan. Muat ulang statusnya.']);
    }

    private function schemaReady(): bool
    {
        return Schema::hasTable('story_estimation_runs')
            && Schema::hasTable('story_estimation_agent_runs')
            && Schema::hasTable('story_estimation_messages');
    }

    private function backToBacklog(Story $story): RedirectResponse
    {
        return redirect()->route('projects.sprint-backlog.index', $story->project_id);
    }
}
