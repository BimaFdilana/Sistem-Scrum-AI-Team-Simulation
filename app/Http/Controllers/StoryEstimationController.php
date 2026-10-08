<?php

namespace App\Http\Controllers;

use App\Models\Story;
use App\Models\StoryEstimationRun;
use App\Services\StoryEstimationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class StoryEstimationController extends Controller
{
    public function store(Request $request, Story $story, StoryEstimationService $estimator): RedirectResponse
    {
        if (! $this->schemaReady()) {
            return $this->backToBacklog($story)->withErrors([
                'estimation' => 'Estimasi Fibonacci belum tersedia sampai migrasi database dijalankan.',
            ]);
        }

        if (! $story->sprint_id || $story->sprint?->status !== 'PLANNING') {
            return $this->backToBacklog($story)->withErrors([
                'estimation' => 'Estimasi hanya dapat diminta untuk User Story dalam Sprint berstatus PLANNING.',
            ]);
        }

        $run = $estimator->estimate($story->load('actor'), $request->user());

        return match ($run->status) {
            'COMPLETED' => $this->backToBacklog($story)->with('status', 'Diskusi estimasi Fibonacci selesai. PO dapat meninjau hasil di chat Scrum Master dan menetapkan poin final.'),
            'PARTIAL' => $this->backToBacklog($story)->withErrors([
                'estimation' => 'Sebagian agent atau Scrum Master belum memberi hasil lengkap. Periksa log aktivitas dan chat Scrum Master sebelum meminta ulang.',
            ]),
            'STALE' => $this->backToBacklog($story)->withErrors([
                'estimation' => 'Story berubah saat estimasi berlangsung. Hasil ditandai kedaluwarsa; jalankan ulang untuk revisi terbaru.',
            ]),
            default => $this->backToBacklog($story)->withErrors([
                'estimation' => 'Agent belum menghasilkan estimasi. Periksa koneksi agent di log aktivitas lalu coba lagi.',
            ]),
        };
    }

    public function approve(Request $request, Story $story, StoryEstimationRun $run): RedirectResponse
    {
        if (! $this->schemaReady()) {
            return $this->backToBacklog($story)->withErrors([
                'estimation' => 'Persetujuan poin menunggu migrasi database estimasi.',
            ]);
        }

        abort_unless((int) $run->story_id === (int) $story->id, 404);

        $data = $request->validate([
            'estimate_points' => ['required', 'integer', Rule::in(StoryEstimationService::FIBONACCI_POINTS)],
        ]);

        $validAgentRounds = $run->agentRuns()
            ->where('status', 'COMPLETED')
            ->whereIn('profile_slug', ['backend-developer', 'frontend-developer', 'qa-engineer'])
            ->count();

        if ($run->status !== 'COMPLETED'
            || $run->story_revision !== $story->revision
            || $validAgentRounds !== 6
            || $story->sprint?->status !== 'PLANNING') {
            return $this->backToBacklog($story)->withErrors([
                'estimation' => 'Hanya hasil lengkap untuk revisi story yang masih berlaku dan Sprint PLANNING yang dapat ditetapkan.',
            ]);
        }

        DB::transaction(function () use ($request, $story, $run, $data): void {
            $lockedStory = Story::query()->lockForUpdate()->findOrFail($story->id);
            $lockedRun = StoryEstimationRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($lockedRun->status !== 'COMPLETED' || $lockedRun->story_revision !== $lockedStory->revision) {
                abort(409, 'Estimasi sudah kedaluwarsa.');
            }

            $lockedStory->forceFill(['estimate_points' => $data['estimate_points']])->save();
            $lockedRun->forceFill([
                'approved_points' => $data['estimate_points'],
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ])->save();
        });

        return $this->backToBacklog($story)->with('status', 'PO menetapkan '.$data['estimate_points'].' poin Fibonacci untuk '.$story->display_id.'.');
    }

    private function schemaReady(): bool
    {
        return Schema::hasTable('story_estimation_runs') && Schema::hasTable('story_estimation_agent_runs');
    }

    private function backToBacklog(Story $story): RedirectResponse
    {
        return redirect()->route('projects.sprint-backlog.index', $story->project_id);
    }
}
