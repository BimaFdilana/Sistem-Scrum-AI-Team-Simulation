<?php

namespace App\Http\Controllers;

use App\Jobs\BreakdownExecutionJob;
use App\Models\ExecutionRun;
use App\Models\Story;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ExecutionController extends Controller
{
    public function store(Story $story): RedirectResponse
    {
        $story->load('project');
        if (! $story->estimate_points) {
            return back()->withErrors(['execution' => 'Story belum memiliki estimasi Fibonacci.']);
        }
        if (! $story->project?->workspace_path || ! is_dir($story->project->workspace_path)) {
            return back()->withErrors(['execution' => 'Baseline project belum siap (tunggu scaffold/clone selesai).']);
        }
        $active = ExecutionRun::query()->where('story_id', $story->id)
            ->whereIn('status', ['QUEUED', 'BREAKDOWN', 'AWAITING_PERMISSION', 'EXECUTING', 'REVIEWING', 'MERGING'])
            ->exists();
        if ($active) {
            return back()->withErrors(['execution' => 'Sudah ada eksekusi yang berjalan untuk story ini.']);
        }

        $run = ExecutionRun::query()->create([
            'story_id' => $story->id,
            'requested_by' => auth()->id(),
            'status' => 'QUEUED',
        ]);
        BreakdownExecutionJob::dispatch($run->id);

        return redirect()->route('stories.executions.show', [$story, $run])
            ->with('status', 'Eksekusi dimulai. Scrum Master sedang memecah story menjadi task.');
    }

    public function show(Story $story, ExecutionRun $run): View
    {
        abort_unless($run->story_id === $story->id, 404);

        return view('executions.show', [
            'story' => $story,
            'run' => $run->load(['tasks', 'messages', 'permissionRequest']),
            'activePage' => 'backlog',
        ]);
    }
}
