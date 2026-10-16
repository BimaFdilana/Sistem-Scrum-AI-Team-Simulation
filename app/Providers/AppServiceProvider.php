<?php

namespace App\Providers;

use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Badge jumlah notifikasi belum dibaca di sidebar (semua halaman workspace).
        View::composer('layouts.workspace', function ($view): void {
            $count = 0;
            if (auth()->check()) {
                try {
                    $count = auth()->user()->unreadNotificationsCount();
                } catch (\Throwable) {
                    // Tabel belum ada (sebelum migrasi) — jangan gagalkan render.
                    $count = 0;
                }
            }
            $view->with('unreadNotificationsCount', $count);
        });
    }
}
