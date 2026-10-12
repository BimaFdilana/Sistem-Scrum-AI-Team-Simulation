<?php

namespace App\Http\Controllers;

use App\Jobs\BreakdownExecutionJob;
use App\Models\ExecutionRun;
use App\Models\Story;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ExecutionController extends Controller
{
    public function store(Story $story): RedirectResponse
    {
        $story->load('project');
        if ($story->isAccepted()) {
            return back()->withErrors(['execution' => 'Story sudah diterima PO ('.$story->acceptanceLabel().') dan tidak bisa dieksekusi lagi.']);
        }
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

    /** Keputusan final PO: Berhasil / Gagal. Mengunci story dari eksekusi ulang. */
    public function accept(Request $request, Story $story, ExecutionRun $run): RedirectResponse
    {
        abort_unless((int) $run->story_id === (int) $story->id, 404);
        $status = $request->input('status');
        if (! in_array($status, ['BERHASIL', 'GAGAL'], true)) {
            return back()->withErrors(['acceptance' => 'Status keputusan tidak valid.']);
        }
        if ($story->isAccepted()) {
            return back()->withErrors(['acceptance' => 'Story sudah diterima sebelumnya.']);
        }
        if (! $run->isTerminal()) {
            return back()->withErrors(['acceptance' => 'Keputusan final hanya untuk eksekusi yang sudah selesai atau diblokir.']);
        }

        $story->forceFill([
            'acceptance_status' => $status,
            'accepted_at' => now(),
        ])->save();

        $label = $status === 'BERHASIL' ? 'Berhasil' : 'Gagal';

        return redirect()->route('stories.executions.show', [$story, $run])
            ->with('status', 'Story diterima PO dengan status '.$label.'. Eksekusi ulang dikunci.');
    }
}
