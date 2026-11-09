<?php

namespace App\Http\Controllers;

use App\Models\ExecutionRun;
use App\Models\StoryEstimationRun;
use App\Services\StoryEstimationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Data untuk dock aktivitas mengambang: run yang sedang berjalan, butuh
 * tindakan PO, atau baru selesai. Dipoll dari semua halaman workspace,
 * jadi murah: satu query run + relasi ringkas, dan ETag agar 304 bila tak berubah.
 */
class ActivityController extends Controller
{
    private const ACTIVE = ['QUEUED', 'BREAKDOWN', 'AWAITING_PERMISSION', 'EXECUTING', 'REVIEWING', 'QA_REVIEW', 'MERGING'];

    private const STAGES = [
        ['key' => 'plan', 'label' => 'Rencana'],
        ['key' => 'permission', 'label' => 'Izin'],
        ['key' => 'work', 'label' => 'Pengerjaan'],
        ['key' => 'qa', 'label' => 'QA'],
        ['key' => 'merge', 'label' => 'Selesai'],
    ];

    public function active(Request $request): JsonResponse
    {
        $runs = ExecutionRun::query()
            ->with(['story', 'tasks' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])
            ->where(function ($q) {
                $q->whereIn('status', [...self::ACTIVE, ExecutionRun::NEEDS_PO])
                    ->orWhere(fn ($q) => $q->whereIn('status', ['COMPLETED', 'FAILED', 'BLOCKED'])
                        ->where('updated_at', '>=', now()->subMinutes(30)));
            })
            ->latest('updated_at')
            ->limit(5)
            ->get();

        $payload = [
            'runs' => $runs->map(fn (ExecutionRun $run) => $this->present($run))->values()->all(),
            'estimations' => $this->estimations($request),
        ];
        $all = collect($payload['runs'])->concat($payload['estimations']);
        $payload['attention'] = $all->where('state', 'attention')->count();
        $payload['running'] = $all->where('state', 'running')->count();

        $response = response()->json($payload)->setEtag(md5(json_encode($payload)));
        $response->headers->set('Cache-Control', 'private, no-cache');
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Diskusi Fibonacci milik pengguna: yang sedang berjalan atau baru selesai (30 menit).
     * Menggantikan dialog progres lama; tampil di dock yang sama dengan eksekusi.
     *
     * @return array<int, array<string, mixed>>
     */
    private function estimations(Request $request): array
    {
        if (! Schema::hasTable('story_estimation_runs') || ! $request->user()) {
            return [];
        }

        return StoryEstimationRun::query()
            ->with(['story', 'agentRuns'])
            ->where('requested_by', $request->user()->id)
            ->where(function ($q) {
                $q->whereIn('status', ['QUEUED', 'RUNNING', 'REPORT_PENDING'])
                    ->orWhere(fn ($q) => $q->whereIn('status', ['COMPLETED', 'FAILED', 'PARTIAL', 'NO_CONSENSUS'])
                        ->where('updated_at', '>=', now()->subMinutes(30)));
            })
            ->latest('updated_at')
            ->limit(5)
            ->get()
            ->filter(fn (StoryEstimationRun $run) => $run->story !== null)
            ->map(fn (StoryEstimationRun $run) => $this->presentEstimation($run))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function presentEstimation(StoryEstimationRun $run): array
    {
        $names = ['backend-developer' => 'Backend', 'frontend-developer' => 'Frontend', 'qa-engineer' => 'QA'];
        $round = min(2, max(1, (int) $run->agentRuns->max('round')));
        $current = $run->agentRuns->where('round', $round);
        $completed = $run->agentRuns->where('status', 'COMPLETED')->count();
        $running = $current->where('status', 'RUNNING')->sortByDesc('id')->first();
        $failed = $run->agentRuns->where('status', 'FAILED')->sortByDesc('id')->first();

        $state = match ($run->status) {
            'COMPLETED' => 'done',
            'FAILED', 'PARTIAL', 'NO_CONSENSUS' => 'attention',
            default => 'running',
        };
        $headline = match ($run->status) {
            'QUEUED' => 'Menunggu worker',
            'RUNNING' => 'Putaran '.$round.'/2 · '.($running ? ($names[$running->profile_slug] ?? $running->profile_slug).' menjawab' : 'agen berdiskusi'),
            'REPORT_PENDING' => 'Scrum Master menyusun laporan',
            'COMPLETED' => 'Selesai · '.$run->consensus_points.' poin ('.StoryEstimationService::category((int) $run->consensus_points).')',
            'NO_CONSENSUS' => 'Tanpa konsensus',
            default => $failed ? ($names[$failed->profile_slug] ?? $failed->profile_slug).' gagal pada putaran '.$failed->round : 'Berhenti sebelum selesai',
        };

        $actions = [];
        if (in_array($run->status, ['FAILED', 'PARTIAL', 'NO_CONSENSUS'], true)) {
            $actions[] = [
                'key' => 'retry',
                'label' => 'Ulangi diskusi',
                'method' => 'post',
                'url' => route('stories.estimations.retry', [$run->story, $run]),
            ];
        }

        return [
            'id' => $run->id,
            'kind' => 'estimation',
            'label' => Str::limit($run->story->display_id.' — '.$run->story->effectiveTitle(), 70),
            'status' => $run->status,
            'state' => $state,
            'headline' => $headline,
            'round' => $round,
            'total_rounds' => 2,
            'progress' => ['done' => min(6, $completed), 'total' => 6],
            'agents' => collect($names)->map(fn (string $name, string $slug) => [
                'name' => $name,
                'status' => (string) ($current->firstWhere('profile_slug', $slug)?->status ?? 'PENDING'),
            ])->values()->all(),
            'points' => $run->consensus_points,
            'detail_url' => route('stories.discussion.show', $run->story),
            'actions' => $actions,
        ];
    }

    /** @return array<string, mixed> */
    private function present(ExecutionRun $run): array
    {
        $state = match (true) {
            $run->status === 'COMPLETED' => 'done',
            in_array($run->status, [ExecutionRun::NEEDS_PO, 'AWAITING_PERMISSION', 'FAILED', 'BLOCKED'], true) => 'attention',
            default => 'running',
        };

        $stage = match ($run->status) {
            'QUEUED', 'BREAKDOWN' => 0,
            'AWAITING_PERMISSION' => 1,
            'EXECUTING' => 2,
            'REVIEWING', 'QA_REVIEW' => 3,
            'MERGING', 'COMPLETED' => 4,
            default => $this->stageFromTasks($run),
        };

        $tasks = $run->tasks;
        $detailUrl = $run->story ? route('stories.executions.show', [$run->story, $run]) : null;

        $actions = [];
        if ($run->isWaitingForPo()) {
            $actions[] = [
                'key' => 'resume',
                'label' => 'Lanjutkan',
                'method' => 'post',
                'url' => route('stories.executions.resume', [$run->story, $run]),
            ];
        }

        return [
            'id' => $run->id,
            'label' => Str::limit($run->subjectLabel(), 70),
            'status' => $run->status,
            'state' => $state,
            'headline' => $this->headline($run),
            'stage' => ['index' => $stage, 'steps' => self::STAGES],
            'progress' => [
                'done' => $tasks->whereIn('status', ['APPROVED', 'QA_PENDING'])->count(),
                'total' => $tasks->count(),
            ],
            'tasks' => $tasks->take(8)->map(fn ($t) => [
                'title' => Str::limit($t->title, 60),
                'assignee' => $t->assignee === 'frontend-developer' ? 'FE' : ($t->assignee === 'backend-developer' ? 'BE' : (string) $t->assignee),
                'status' => $t->status,
            ])->values()->all(),
            'log' => $run->messages()->latest('id')->limit(4)->get()->reverse()->map(fn ($m) => [
                'who' => $m->sender_slug,
                'text' => Str::limit((string) $m->content, 140),
            ])->values()->all(),
            'detail_url' => $detailUrl,
            'actions' => $actions,
        ];
    }

    private function headline(ExecutionRun $run): string
    {
        return match ($run->status) {
            'QUEUED' => 'Menunggu giliran',
            'BREAKDOWN' => 'Scrum Master memecah story',
            'AWAITING_PERMISSION' => 'Menunggu izin Anda',
            'EXECUTING' => 'Developer mengerjakan task',
            'REVIEWING', 'QA_REVIEW' => 'QA memeriksa hasil',
            'MERGING' => 'Menggabungkan hasil',
            ExecutionRun::NEEDS_PO => 'Berhenti, butuh keputusan Anda',
            'COMPLETED' => 'Selesai',
            'FAILED' => 'Gagal',
            'BLOCKED' => 'Diblokir',
            default => $run->status,
        };
    }

    private function stageFromTasks(ExecutionRun $run): int
    {
        return $run->tasks->contains(fn ($t) => in_array($t->status, ['QA_PENDING', 'IN_REVIEW'], true)) ? 3 : 2;
    }
}
