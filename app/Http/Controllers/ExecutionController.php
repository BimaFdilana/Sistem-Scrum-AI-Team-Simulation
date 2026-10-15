<?php

namespace App\Http\Controllers;

use App\Jobs\BreakdownExecutionJob;
use App\Models\ExecutionRun;
use App\Models\PermissionRequest;
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
        // Kedaluwarsakan permintaan izin yang menggantung > 24 jam dulu,
        // agar tidak memblokir eksekusi baru secara permanen.
        PermissionRequest::expireStale();

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

        // Fase 3 GitHub: buat story branch di GitHub (graceful bila gagal/tidak terhubung).
        $this->createStoryBranch($story, $run);

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

        // Fase 3 GitHub: bila BERHASIL dan ada story branch, merge ke integration.
        // Konflik → tolak, story TIDAK ditandai Berhasil.
        if ($status === 'BERHASIL' && filled($run->github_branch)) {
            $mergeError = $this->mergeToIntegration($story, $run);
            if ($mergeError !== null) {
                return back()->withErrors(['acceptance' => $mergeError]);
            }
        }

        $story->forceFill([
            'acceptance_status' => $status,
            'accepted_at' => now(),
        ])->save();

        $label = $status === 'BERHASIL' ? 'Berhasil' : 'Gagal';

        return redirect()->route('stories.executions.show', [$story, $run])
            ->with('status', 'Story diterima PO dengan status '.$label.'. Eksekusi ulang dikunci.');
    }

    /**
     * Buat story branch di GitHub saat eksekusi dimulai.
     * Graceful: gagal → log saja, eksekusi tetap jalan lokal.
     */
    protected function createStoryBranch(Story $story, ExecutionRun $run): void
    {
        try {
            $project = $story->project;
            if (! $project || ! filled($project->github_owner) || ! filled($project->github_repo)) {
                return;
            }

            $service = app(\App\Services\GithubProjectService::class);
            if (! $service->isConnected()) {
                return;
            }

            $branch = $service->createStoryBranch(
                $project->github_owner,
                $project->github_repo,
                $story->id,
                $story->title ?? $story->refined_title ?? 'story'
            );

            if ($branch !== null) {
                $run->forceFill(['github_branch' => $branch])->save();
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Story branch GitHub gagal dibuat', [
                'story_id' => $story->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Merge story branch → integration di GitHub.
     * Return null bila sukses, pesan error Bahasa Indonesia bila gagal.
     */
    protected function mergeToIntegration(Story $story, ExecutionRun $run): ?string
    {
        try {
            $project = $story->project;
            if (! $project || ! filled($project->github_owner) || ! filled($project->github_repo)) {
                return null; // Flow lokal — tidak ada yang di-merge.
            }

            $service = app(\App\Services\GithubProjectService::class);
            if (! $service->isConnected()) {
                return null;
            }

            $owner = $project->github_owner;
            $repo = $project->github_repo;

            // Pastikan branch integration ada.
            try {
                $service->ensureIntegrationBranch($owner, $repo);
            } catch (\Throwable $e) {
                return 'Gagal menyiapkan branch integration di GitHub: '.$e->getMessage();
            }

            // Merge via GitHub API.
            $github = app(\App\Services\GithubApiClient::class);
            $result = $github->mergeBranch($owner, $repo, 'integration', $run->github_branch);

            if ($result === null || ! ($result['merged'] ?? false)) {
                return 'Merge ke branch integration gagal — kemungkinan ada konflik. '.
                    'Periksa di GitHub ('.$owner.'/'.$repo.') dan selesaikan konflik secara manual, '.
                    'lalu coba Accept lagi.';
            }

            // Sinkronkan branch integration ke lokal agar demo bisa pakai kode terbaru.
            $workspace = (string) $project->workspace_path;
            if ($workspace !== '' && is_dir($workspace)) {
                try {
                    $service->syncBranchFromRemote($workspace, 'integration');
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Sync integration ke lokal gagal', [
                        'error' => $e->getMessage(),
                    ]);
                    // Tidak fatal — merge GitHub sudah sukses.
                }
            }

            return null;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Merge story ke integration gagal', [
                'story_id' => $story->id,
                'error' => $e->getMessage(),
            ]);

            return 'Gagal merge ke branch integration: '.$e->getMessage();
        }
    }
}
