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
            'content' => 'PO menyetujui eksekusi. Agen mulai mengerjakan '.count($run->tasks).' task.',
        ]);

        foreach ($run->tasks()->where('status', 'PENDING')->get() as $task) {
            ExecuteExecutionTaskJob::dispatch($run->id, $task->id);
        }

        return back()->with('status', 'Izin disetujui. Agen mulai bekerja.');
    }

    public function reject(PermissionRequest $permissionRequest): RedirectResponse
    {
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
}
