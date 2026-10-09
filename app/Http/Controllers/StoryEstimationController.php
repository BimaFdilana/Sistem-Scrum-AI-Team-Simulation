<?php

namespace App\Http\Controllers;

use App\Models\Story;
use App\Models\StoryEstimationRun;
use App\Services\StoryEstimationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class StoryEstimationController extends Controller
{
    public function store(Request $request, Story $story, StoryEstimationService $estimator): RedirectResponse
    {
        if (! $this->schemaReady()) {
            return $this->backToBacklog($story)->withErrors(['estimation' => 'Diskusi Fibonacci menunggu migrasi database estimasi.']);
        }
        if (! $story->sprint_id || $story->sprint?->status !== 'PLANNING') {
            return $this->backToBacklog($story)->withErrors(['estimation' => 'Diskusi hanya dapat diminta untuk User Story pada Sprint PLANNING.']);
        }
        if ($story->estimationRuns()->where('story_revision', $story->revision)->where('status', 'RUNNING')->exists()) {
            return $this->backToBacklog($story)->withErrors(['estimation' => 'Diskusi story ini masih berlangsung; jangan membuat run ganda.']);
        }

        $run = $estimator->estimate($story->load('actor'), $request->user());

        return match ($run->status) {
            'COMPLETED' => $this->backToBacklog($story)->with('status', 'Poin Fibonacci disepakati oleh FE, BE, QA dan dilaporkan Scrum Master ke PO.'),
            'NO_CONSENSUS' => $this->backToBacklog($story)->withErrors(['estimation' => 'Tim belum mencapai kesepakatan dalam tiga putaran. Tidak ada poin final.']),
            'REPORT_PENDING' => $this->backToBacklog($story)->withErrors(['estimation' => 'Tim sepakat, tetapi laporan Scrum Master belum tersimpan. Gunakan Coba Lagi Laporan, bukan mengulang diskusi.']),
            'STALE' => $this->backToBacklog($story)->withErrors(['estimation' => 'Story berubah saat diskusi. Hasil lama tidak berlaku.']),
            default => $this->backToBacklog($story)->withErrors(['estimation' => 'Diskusi belum selesai. Periksa catatan tim dan status koneksi agent.']),
        };
    }

    public function discussion(Request $request, Story $story): View
    {
        $ready = $this->schemaReady();

        return view('stories.discussion', [
            'story' => $story->load('project', 'sprint'),
            'runs' => $ready ? $story->estimationRuns()->where('requested_by', $request->user()->id)
                ->with(['agentRuns', 'messages'])->get() : collect(),
            'schemaReady' => $ready,
            'activePage' => 'backlog',
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
