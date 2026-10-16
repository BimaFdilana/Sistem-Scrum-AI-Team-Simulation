<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(): View
    {
        $notifications = auth()->user()->notifications()->latest()->paginate(20);

        return view('workspace.notifications', [
            'notifications' => $notifications,
            'activePage' => 'notifications',
            'breadcrumb' => 'Notifikasi',
            'title' => 'Notifikasi',
        ]);
    }

    public function markAsRead(Notification $notification): RedirectResponse
    {
        abort_unless($notification->user_id === auth()->id(), 403);
        $notification->markAsRead();

        return back();
    }

    public function markAllAsRead(): RedirectResponse
    {
        auth()->user()->notifications()->whereNull('read_at')->update(['read_at' => now()]);

        return back()->with('status', 'Semua notifikasi ditandai sudah dibaca.');
    }
}
