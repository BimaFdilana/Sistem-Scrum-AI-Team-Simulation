<?php

namespace App\Http\Controllers;

use App\Jobs\ExecuteExecutionTaskJob;
use App\Models\PermissionRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** Menu "Accepted": PO menyetujui / menolak permintaan izin agent. */
class PermissionRequestController extends Controller
{
    public function index(): View
    {
        PermissionRequest::expireStale();

        $requests = PermissionRequest::query()
            ->with(['run.story', 'run.repairOrder', 'requester'])
            ->orderByRaw("CASE WHEN status = 'PENDING' THEN 0 ELSE 1 END")
            ->latest()
            ->paginate(20);

        return view('accepted.index', [
            'requests' => $requests,
            'activePage' => 'accepted',
        ]);
    }

    public function approve(PermissionRequest $permissionRequest): RedirectResponse
    {
        PermissionRequest::expireStale();

        $run = $permissionRequest->run;
        if ($permissionRequest->status !== 'PENDING' || $run->status !== 'AWAITING_PERMISSION') {
            return back()->withErrors(['permission' => 'Permintaan sudah diproses.']);
        }

        $permissionRequest->forceFill([
            'status' => 'APPROVED',
            'resolved_by' => auth()->id(),
            'resolved_at' => now(),
        ])->save();
        $run->forceFill(['status' => 'EXECUTING'])->save();
        $run->messages()->create([
            'sender_slug' => 'po',
            'recipient_slug' => 'scrum-master',
            'content' => 'PO menyetujui eksekusi. Agen mulai mengerjakan '.count($run->tasks).' task secara berurutan.',
        ]);

        // Model berurutan (keputusan Bima 2026-10-31): hanya task pertama
        // yang di-dispatch; task berikutnya di-dispatch ReviewExecutionTaskJob
        // setelah task sebelumnya QA-APPROVED — bukan semua sekaligus.
        $first = $run->nextPendingTask();
        if ($first) {
            ExecuteExecutionTaskJob::dispatch($run->id, $first->id);
        }

        return back()->with('status', 'Izin disetujui. Agen mulai bekerja.');
    }

    public function reject(PermissionRequest $permissionRequest): RedirectResponse
    {
        PermissionRequest::expireStale();

        $run = $permissionRequest->run;
        if ($permissionRequest->status !== 'PENDING' || $run->status !== 'AWAITING_PERMISSION') {
            return back()->withErrors(['permission' => 'Permintaan sudah diproses.']);
        }

        $permissionRequest->forceFill([
            'status' => 'REJECTED',
            'resolved_by' => auth()->id(),
            'resolved_at' => now(),
        ])->save();
        $run->forceFill([
            'status' => 'FAILED',
            'error_code' => 'PERMISSION_REJECTED',
            'completed_at' => now(),
        ])->save();

        return back()->with('status', 'Izin ditolak. Eksekusi dibatalkan.');
    }

    /** Batalkan permintaan izin: story kembali bisa dieksekusi ulang. */
    public function cancel(PermissionRequest $permissionRequest): RedirectResponse
    {
        PermissionRequest::expireStale();

        $run = $permissionRequest->run;
        if ($permissionRequest->status !== 'PENDING' || $run->status !== 'AWAITING_PERMISSION') {
            return back()->withErrors(['permission' => 'Permintaan sudah diproses atau kedaluwarsa.']);
        }

        $permissionRequest->forceFill([
            'status' => 'CANCELLED',
            'resolved_by' => auth()->id(),
            'resolved_at' => now(),
        ])->save();
        $run->forceFill([
            'status' => 'FAILED',
            'error_code' => 'PERMISSION_CANCELLED',
            'completed_at' => now(),
        ])->save();
        $run->messages()->create([
            'sender_slug' => 'po',
            'recipient_slug' => 'scrum-master',
            'content' => 'PO membatalkan permintaan izin. Eksekusi dapat diulang dari awal.',
        ]);

        return back()->with('status', 'Permintaan izin dibatalkan. Story bisa dieksekusi ulang.');
    }
}
