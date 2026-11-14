<?php

namespace App\Providers;

use App\Models\ExecutionRun;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Story;
use App\Support\Execution\ProjectRuns;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
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

        // Polling dock aktivitas memakai pembatas bernama sendiri. throttle:N,1
        // bawaan berbagi satu hitungan per pengguna untuk SEMUA rute, sehingga polling
        // tiap 3 detik menghabiskan jatah rute lain (mulai eksekusi, lanjutkan, dst).
        RateLimiter::for('activity', fn (Request $request) => Limit::perMinute(120)->by('activity|'.($request->user()?->id ?? $request->ip())));

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

            // Project aktif untuk grup PROJECT di sidebar: dari halaman yang dibuka
            // (project/sprint/story/eksekusi/chat), lalu diingat di sesi.
            $navProject = null;
            $navProjects = collect();
            try {
                $route = request()->route();
                foreach (['project', 'story', 'sprint', 'run'] as $param) {
                    $value = $route?->parameter($param);
                    $navProject = match (true) {
                        $value instanceof Project => $value,
                        $value instanceof Story, $value instanceof Sprint => $value->project,
                        $value instanceof ExecutionRun => $value->story?->project,
                        default => null,
                    };
                    if ($navProject !== null) {
                        break;
                    }
                }
                if ($navProject === null && request()->routeIs('chat.index')) {
                    $navProject = request('sprint') ? Sprint::query()->find((int) request('sprint'))?->project
                        : (request('project_id') ? Project::query()->find((int) request('project_id')) : null);
                }
                if ($navProject !== null && request()->hasSession()) {
                    request()->session()->put('nav_project_id', $navProject->id);
                }
                $navProjects = Project::query()->orderBy('name')->get(['id', 'name']);
                if ($navProject === null) {
                    $remembered = request()->hasSession() ? request()->session()->get('nav_project_id') : null;
                    $navProject = $navProjects->firstWhere('id', $remembered) ?? $navProjects->first();
                }
            } catch (\Throwable) {
                $navProject = null;
            }
            $decisions = 0;
            try {
                $decisions = $navProject ? ProjectRuns::decisionCount($navProject) : 0;
            } catch (\Throwable) {
                $decisions = 0;
            }
            $view->with('navProject', $navProject)->with('navProjects', $navProjects)->with('navDecisionCount', $decisions);
        });
    }
}
