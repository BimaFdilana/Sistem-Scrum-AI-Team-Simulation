<?php

namespace App\Http\Controllers;

use App\Jobs\BreakdownExecutionJob;
use App\Jobs\RefreshDemoJob;
use App\Models\ExecutionRun;
use App\Models\PermissionRequest;
use App\Models\Project;
use App\Models\Story;
use App\Services\GithubApiClient;
use App\Services\GithubProjectService;
use App\Support\Demo\DemoServerManager;
use App\Support\Execution\DirectWorkspace;
use App\Support\Execution\RuntimeSmoke;
use App\Support\Workspace\BaselineSnapshot;
use App\Support\Workspace\WorktreeManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class ExecutionController extends Controller
{
    public function store(Story $story): RedirectResponse
    {
        $story->load('project');
        if ($story->isLockedFromExecution()) {
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
            ->whereIn('status', ['QUEUED', 'BREAKDOWN', 'AWAITING_PERMISSION', 'EXECUTING', 'REVIEWING', 'QA_REVIEW', 'MERGING'])
            ->exists();
        if ($active) {
            return back()->withErrors(['execution' => 'Sudah ada eksekusi yang berjalan untuk story ini.']);
        }

        // Story yang ditandai GAGAL dibuka lagi: keputusan PO berikutnya untuk run baru ini.
        if ($story->acceptance_status === 'GAGAL') {
            $story->forceFill(['acceptance_status' => null, 'accepted_at' => null])->save();
        }

        $run = ExecutionRun::query()->create([
            'story_id' => $story->id,
            'requested_by' => auth()->id(),
            'status' => 'QUEUED',
            'mode' => config('execution.mode', 'worktree'),
            'qa_mode' => config('execution.qa_mode', 'per_task'),
        ]);

        // Snapshot baseline sebelum agent menyentuh kode (mode langsung).
        if (($run->mode ?: config('execution.mode', 'worktree')) === 'direct' && $story->project) {
            try {
                app(BaselineSnapshot::class)->backup($story->project, 'run-'.$run->id);
            } catch (\Throwable $e) {
                Log::warning('Snapshot baseline gagal', ['run' => $run->id, 'error' => $e->getMessage()]);
            }
        }

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
            'run' => $run->load(['tasks', 'messages', 'permissionRequest', 'findings']),
            'activePage' => 'backlog',
        ]);
    }

    /** PO melanjutkan run yang dihentikan watchdog (NEEDS_PO): task terblokir dijalankan ulang. */
    public function resume(Story $story, ExecutionRun $run): RedirectResponse
    {
        abort_unless((int) $run->story_id === (int) $story->id, 404);

        if (! $run->resumeFromPo()) {
            return back()->withErrors(['acceptance' => 'Eksekusi ini tidak sedang menunggu keputusan PO.']);
        }

        return back()->with('status', 'Eksekusi dilanjutkan.');
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
        // Run yang menunggu PO (NEEDS_PO) boleh ditutup sebagai GAGAL, tidak sebagai BERHASIL.
        $closableByPo = $run->isTerminal() || ($run->isWaitingForPo() && $status === 'GAGAL');
        if (! $closableByPo) {
            return back()->withErrors(['acceptance' => 'Keputusan final hanya untuk eksekusi yang sudah selesai atau diblokir.']);
        }

        // Gate: keputusan BERHASIL menuntut demo yang benar-benar sehat.
        // Sebelumnya tombol ini hanya klik — story bisa diterima walau
        // halamannya 500, tanpa satu pun bukti.
        $demoEvidence = null;
        if ($status === 'BERHASIL' && $story->project !== null) {
            $check = app(DemoServerManager::class)->verify($story->project);

            if (! $check['ok']) {
                return back()->withErrors([
                    'acceptance' => 'Demo belum sehat, keputusan BERHASIL ditolak: '.($check['error'] ?? 'tidak diketahui')
                        .' Perbaiki demo (atau minta SM memperbaiki) lalu coba lagi.',
                ]);
            }

            $demoEvidence = 'Demo terverifikasi '.($check['url'] ?? '-')
                .' HTTP '.($check['http'] ?? '-').' pada '.now()->format('d M Y H:i');

            $smoke = $this->acceptanceSmoke($story->project, $run);
            if ($smoke['blocking'] !== []) {
                return back()->withErrors([
                    'acceptance' => 'Uji jalan menemukan error pada halaman story ini, keputusan BERHASIL ditolak: '
                        .implode(' | ', array_slice($smoke['blocking'], 0, 4))
                        .'. Tandai Gagal lalu jalankan ulang story, atau perbaiki dulu kodenya.',
                ]);
            }
            $demoEvidence .= "\n".$smoke['note'];
        }

        // Mode direct: hasil run ada di branch run; gabungkan bila diterima, kembalikan baseline apa pun hasilnya.
        if ($story->project !== null) {
            $settleError = app(DirectWorkspace::class)->settle($story->project, $run, $status === 'BERHASIL');
            if ($settleError !== null) {
                return back()->withErrors(['acceptance' => $settleError]);
            }
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

        if ($demoEvidence !== null) {
            // Simpan bukti verifikasi pada run supaya keputusan PO bisa
            // ditelusuri (URL, status HTTP, waktu pemeriksaan).
            $run->forceFill(['summary' => trim(((string) $run->summary)."\n".$demoEvidence)])->save();
            $run->messages()->create([
                'sender_slug' => 'po',
                'recipient_slug' => 'team',
                'content' => $demoEvidence,
            ]);
        }

        // Hasil yang baru disetujui harus langsung tampil di demo yang sedang berjalan.
        if ($status === 'BERHASIL' && $story->project_id !== null) {
            RefreshDemoJob::dispatch($story->project_id);
        }

        $label = $status === 'BERHASIL' ? 'Berhasil' : 'Gagal';

        return redirect()->route('stories.executions.show', [$story, $run])
            ->with('status', 'Story diterima PO dengan status '.$label.'.'
                .($status === 'BERHASIL' ? ' Eksekusi ulang dikunci.' : ' Hasilnya tidak digabung; story bisa dieksekusi ulang dari halaman project.'));
    }

    /**
     * Uji jalan seluruh halaman project sebelum story diterima.
     *
     * Error pada halaman milik story ini (rute atau file penyebab termasuk file
     * yang diubah run) memblokir penerimaan; error di halaman lain hanya dicatat
     * sebagai peringatan regresi agar PO tahu, tanpa menyandera story ini.
     *
     * @return array{blocking: array<int, string>, note: string}
     */
    private function acceptanceSmoke(Project $project, ExecutionRun $run): array
    {
        if (! config('execution.smoke_enabled', true)) {
            return ['blocking' => [], 'note' => 'Uji jalan: dimatikan.'];
        }

        $path = rtrim((string) $project->workspace_path, '/');
        $git = app(WorktreeManager::class);
        $changed = [];

        try {
            if ($run->work_branch) {
                $start = 'refs/scrumspace/run-'.$run->id.'-start';
                $changed = $git->changedFilesBetween($path, $git->branchExists($path, $start) ? $start : (string) $run->base_branch, $run->work_branch);
            }
        } catch (\Throwable) {
            $changed = [];
        }

        $smoke = app(RuntimeSmoke::class);
        $onBranch = ! $run->work_branch || $git->currentBranch($path) === $run->work_branch;
        $report = $onBranch ? $smoke->run($project, $changed, 'all') : $smoke->runAtRef($project, $run->work_branch, $changed, 'all');
        if (! $report['ran']) {
            return ['blocking' => [], 'note' => $smoke->summary($report)];
        }

        $blocking = [];
        $warnings = [];
        foreach ($smoke->crashes($report) as $crash) {
            $label = trim(($crash['method'] ?? 'GET').' '.($crash['uri'] ?? '')).': '.mb_substr((string) ($crash['error'] ?? 'HTTP 500'), 0, 160);
            $ownsStory = in_array($crash['route_file'] ?? null, $changed, true) || in_array($crash['file'] ?? null, $changed, true);
            if ($ownsStory) {
                $blocking[] = $label;
            } else {
                $warnings[] = $label;
            }
        }

        // Regresi: bandingkan dengan kode sebelum run ini (titik awal run).
        $start = 'refs/scrumspace/run-'.$run->id.'-start';
        $regressions = [];
        try {
            if ($run->work_branch && $git->branchExists($path, $start)) {
                $base = $smoke->runAtRef($project, $start, $changed, 'all');
                if ($base['ran']) {
                    $regressions = $smoke->regressions($base, $report);
                }
            }
        } catch (\Throwable) {
            $regressions = [];
        }
        foreach ($regressions as $regression) {
            $blocking[] = mb_substr($regression['instruction'], 0, 220);
        }

        $total = count($report['results']);
        $note = 'Uji jalan seluruh halaman: '.$total.' permintaan, '.count($blocking).' error/regresi di halaman story ini';
        if ($warnings !== []) {
            $note .= ', '.count($warnings).' error di halaman lain (regresi atau bug lama): '.implode(' | ', array_slice($warnings, 0, 5));
        }

        return ['blocking' => $blocking, 'note' => $note.'.'];
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

            $service = app(GithubProjectService::class);
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
            Log::warning('Story branch GitHub gagal dibuat', [
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

            $service = app(GithubProjectService::class);
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
            $github = app(GithubApiClient::class);
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
                    Log::warning('Sync integration ke lokal gagal', [
                        'error' => $e->getMessage(),
                    ]);
                    // Tidak fatal — merge GitHub sudah sukses.
                }
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('Merge story ke integration gagal', [
                'story_id' => $story->id,
                'error' => $e->getMessage(),
            ]);

            return 'Gagal merge ke branch integration: '.$e->getMessage();
        }
    }
}
