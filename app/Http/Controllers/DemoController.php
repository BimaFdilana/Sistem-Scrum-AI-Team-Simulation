<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Support\Demo\DemoServerManager;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

class DemoController extends Controller
{
    public function start(Project $project, DemoServerManager $demos): RedirectResponse
    {
        try {
            $demos->prune();
            $demo = $demos->start($project);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['demo' => $exception->getMessage()]);
        }

        return back()->with('status', 'Demo berjalan di '.$demos->url($demo, request()->getHost()).' (otomatis berhenti setelah '.config('demo.ttl_minutes').' menit).');
    }

    /** Status demo untuk polling UI (tanpa memulai/menghentikan apa pun). */
    public function status(Project $project, DemoServerManager $demos): \Illuminate\Http\JsonResponse
    {
        // Baca record RUNNING terakhir apa adanya: endpoint status tidak boleh
        // memutuskan hidup/mati berdasarkan PID (bisa beda namespace proses).
        $demo = \App\Models\DemoServer::query()
            ->where('project_id', $project->id)
            ->where('status', 'RUNNING')
            ->latest('id')
            ->first();

        if (! $demo) {
            return response()->json(['running' => false, 'status' => null]);
        }

        return response()->json([
            'running' => $demo->status === 'RUNNING',
            'status' => $demo->status,
            'port' => $demo->port,
            'url' => $demo->status === 'RUNNING' ? $demos->url($demo, request()->getHost()) : null,
            'health' => $demo->health_status,
            'http' => $demo->health_http_status,
            'error' => $demo->health_error,
            'checked_at' => $demo->health_checked_at?->diffForHumans(),
            'expires_at' => $demo->expires_at?->format('H:i'),
        ]);
    }

    /** Bangun ulang aset frontend (CSS/JS) project. */
    public function rebuildAssets(Project $project, DemoServerManager $demos): RedirectResponse
    {
        $result = $demos->rebuildAssets($project);

        return $result['ok']
            ? back()->with('status', $result['message'])
            : back()->withErrors(['demo' => $result['message']]);
    }

    public function stop(Project $project, DemoServerManager $demos): RedirectResponse
    {
        $demo = $demos->runningFor($project);
        if ($demo) {
            $demos->stop($demo);
        }

        return back()->with('status', 'Demo dihentikan.');
    }

    /**
     * Fase 3 GitHub: PO minta SM memperbaiki demo yang bermasalah.
     * Membuat RepairOrder khusus dengan target branch integration.
     */
    public function requestFix(Project $project): RedirectResponse
    {
        $order = \App\Models\RepairOrder::query()->create([
            'requested_by' => auth()->id(),
            'project_id' => $project->id,
            'title' => 'Perbaiki demo (branch integration)',
            'summary' => 'Demo project bermasalah. Periksa branch integration, temukan penyebabnya, dan perbaiki agar demo berjalan normal.',
            'original_message' => 'Minta SM perbaiki demo yang bermasalah di branch integration.',
            'status' => 'DRAFT',
            'target_branch' => 'integration',
        ]);

        return redirect()->route('repair-orders.show', $order)
            ->with('status', 'Permintaan perbaikan demo dibuat. Konfirmasi detailnya lalu jalankan.');
    }
}
