<?php

namespace App\Http\Controllers;

use App\Jobs\BreakdownExecutionJob;
use App\Models\ExecutionRun;
use App\Models\Project;
use App\Models\RepairOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Menu "Perintah Perbaikan" — terpisah dari Sprint Backlog.
 *
 * Alur: PO memerintah SM dari chat -> SM klasifikasikan & tampilkan ringkasan ->
 * PO konfirmasi (wajib) -> perintah masuk sini -> PO tekan Jalankan -> pipeline
 * eksekusi yang sama (breakdown -> izin di menu Accepted -> FE/BE -> QA -> merge)
 * -> PO beri keputusan final Berhasil/Gagal.
 */
class RepairOrderController extends Controller
{
    public function index(): View
    {
        $orders = RepairOrder::query()
            ->with(['project', 'requester'])
            ->withCount('runs')
            ->orderByRaw("CASE WHEN status IN ('DRAFT','CONFIRMED','RUNNING') THEN 0 ELSE 1 END")
            ->latest()
            ->paginate(20);

        return view('repair-orders.index', [
            'orders' => $orders,
            'activePage' => 'repair-orders',
        ]);
    }

    public function show(RepairOrder $repairOrder): View
    {
        $repairOrder->load(['project', 'requester', 'runs' => fn ($q) => $q->latest('id')]);

        return view('repair-orders.show', [
            'order' => $repairOrder,
            'activePage' => 'repair-orders',
        ]);
    }

    /** Konfirmasi PO dari kartu ringkasan di chat. Tidak ada yang jalan otomatis. */
    public function confirm(Request $request, RepairOrder $repairOrder): JsonResponse
    {
        if ($repairOrder->status !== 'DRAFT') {
            return response()->json(['message' => 'Perintah sudah diproses sebelumnya.'], 422);
        }

        $data = $request->validate([
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
        ]);

        $projectId = $repairOrder->project_id ?? $data['project_id'] ?? null;
        if ($projectId === null) {
            return response()->json(['message' => 'Pilih project yang dimaksud terlebih dahulu.'], 422);
        }
        $project = Project::query()->findOrFail($projectId);

        $repairOrder->forceFill([
            'project_id' => $project->id,
            'status' => 'CONFIRMED',
        ])->save();

        return response()->json([
            'message' => 'Perintah perbaikan disimpan.',
            'redirect_url' => route('repair-orders.show', $repairOrder, false),
        ]);
    }

    /** PO membatalkan perintah yang belum dikonfirmasi. */
    public function cancel(RepairOrder $repairOrder): JsonResponse
    {
        if ($repairOrder->status !== 'DRAFT') {
            return response()->json(['message' => 'Perintah sudah diproses sebelumnya.'], 422);
        }
        $repairOrder->forceFill(['status' => 'CANCELLED'])->save();

        return response()->json(['message' => 'Perintah dibatalkan.']);
    }

    /** PO menjalankan perintah: buat execution run memakai pipeline yang sama. */
    public function run(RepairOrder $repairOrder): RedirectResponse
    {
        if ($repairOrder->status !== 'CONFIRMED') {
            return back()->withErrors(['execution' => 'Perintah harus berstatus "Siap dijalankan".']);
        }
        if ($repairOrder->isAccepted()) {
            return back()->withErrors(['execution' => 'Perintah sudah diterima PO ('.$repairOrder->acceptanceLabel().') dan tidak bisa dijalankan lagi.']);
        }
        $project = $repairOrder->project;
        if (! $project?->workspace_path || ! is_dir($project->workspace_path)) {
            return back()->withErrors(['execution' => 'Baseline project belum siap (tunggu scaffold/clone selesai).']);
        }
        $active = ExecutionRun::query()->where('repair_order_id', $repairOrder->id)
            ->whereIn('status', ['QUEUED', 'BREAKDOWN', 'AWAITING_PERMISSION', 'EXECUTING', 'REVIEWING', 'MERGING'])
            ->exists();
        if ($active) {
            return back()->withErrors(['execution' => 'Sudah ada eksekusi yang berjalan untuk perintah ini.']);
        }

        $run = ExecutionRun::query()->create([
            'story_id' => null,
            'repair_order_id' => $repairOrder->id,
            'requested_by' => auth()->id(),
            'status' => 'QUEUED',
        ]);
        $repairOrder->forceFill(['status' => 'RUNNING'])->save();
        BreakdownExecutionJob::dispatch($run->id);

        return redirect()->route('repair-orders.executions.show', [$repairOrder, $run])
            ->with('status', 'Perintah dijalankan. Scrum Master sedang memecah perintah menjadi task.');
    }

    public function showExecution(RepairOrder $repairOrder, ExecutionRun $run): View
    {
        abort_unless((int) $run->repair_order_id === (int) $repairOrder->id, 404);

        return view('repair-orders.execution', [
            'order' => $repairOrder,
            'run' => $run->load(['tasks', 'messages', 'permissionRequest']),
            'activePage' => 'repair-orders',
        ]);
    }

    /** Keputusan final PO: Berhasil / Gagal. Mengunci perintah dari eksekusi ulang. */
    public function accept(Request $request, RepairOrder $repairOrder, ExecutionRun $run): RedirectResponse
    {
        abort_unless((int) $run->repair_order_id === (int) $repairOrder->id, 404);
        $status = $request->input('status');
        if (! in_array($status, ['BERHASIL', 'GAGAL'], true)) {
            return back()->withErrors(['acceptance' => 'Status keputusan tidak valid.']);
        }
        if ($repairOrder->isAccepted()) {
            return back()->withErrors(['acceptance' => 'Perintah sudah diterima sebelumnya.']);
        }
        if (! $run->isTerminal()) {
            return back()->withErrors(['acceptance' => 'Keputusan final hanya untuk eksekusi yang sudah selesai atau diblokir.']);
        }

        $repairOrder->forceFill([
            'acceptance_status' => $status,
            'accepted_at' => now(),
            'status' => $status === 'BERHASIL' ? 'COMPLETED' : 'FAILED',
        ])->save();

        $label = $status === 'BERHASIL' ? 'Berhasil' : 'Gagal';

        return redirect()->route('repair-orders.executions.show', [$repairOrder, $run])
            ->with('status', 'Perintah diterima PO dengan status '.$label.'. Eksekusi ulang dikunci.');
    }
}
