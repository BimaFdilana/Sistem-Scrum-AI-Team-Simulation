<?php

namespace App\Http\Controllers;

use App\Models\ExecutionRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            ->with(['story', 'repairOrder', 'tasks' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])
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
        ];
        $payload['attention'] = collect($payload['runs'])->where('state', 'attention')->count();
        $payload['running'] = collect($payload['runs'])->where('state', 'running')->count();

        $response = response()->json($payload)->setEtag(md5(json_encode($payload)));
        $response->headers->set('Cache-Control', 'private, no-cache');
        $response->isNotModified($request);

        return $response;
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
        $detailUrl = $run->story
            ? route('stories.executions.show', [$run->story, $run])
            : ($run->repairOrder ? route('repair-orders.executions.show', [$run->repairOrder, $run]) : null);

        $actions = [];
        if ($run->isWaitingForPo()) {
            $actions[] = [
                'key' => 'resume',
                'label' => 'Lanjutkan',
                'method' => 'post',
                'url' => $run->story
                    ? route('stories.executions.resume', [$run->story, $run])
                    : route('repair-orders.executions.resume', [$run->repairOrder, $run]),
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
