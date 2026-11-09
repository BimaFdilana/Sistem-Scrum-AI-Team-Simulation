<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Terapkan pragma SQLite yang membuat penulisan bersamaan aman.
     *
     * Penulisan dari beberapa proses (queue worker, worker agent, web) dengan
     * mode default menghasilkan "database is locked" — penyebab laporan
     * estimasi berhenti di REPORT_PENDING.
     */
    private function tuneSqlite(): void
    {
        if (config('database.default') !== 'sqlite') {
            return;
        }

        $database = (string) config('database.connections.sqlite.database');

        // :memory: (tes) tidak mendukung WAL.
        if ($database === ':memory:' || $database === '') {
            return;
        }

        try {
            $connection = DB::connection();
            $connection->statement('PRAGMA journal_mode=WAL');
            $connection->statement('PRAGMA busy_timeout=5000');
            $connection->statement('PRAGMA synchronous=NORMAL');
        } catch (\Throwable) {
            // Jangan gagalkan boot aplikasi hanya karena pragma.
        }
    }

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
        // SQLite: WAL + busy_timeout mencegah "database is locked" ketika
        // worker queue, worker agent, dan request web menulis bersamaan.
        // Tanpa ini, laporan estimasi (dan job lain) gagal di tengah jalan.
        $this->tuneSqlite();

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
