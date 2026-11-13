<?php

use App\Support\Demo\DemoServerManager;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Watchdog: task/run yang menggantung ditandai agar bisa ditindak PO.
Schedule::command('runs:watchdog')->everyFiveMinutes()->withoutOverlapping();
// Laporan estimasi yang macet (melebihi batas job SM) diselesaikan dengan poin tersimpan.
Schedule::command('estimations:recover --minutes=45')->everyTenMinutes()->withoutOverlapping();
// Demo yang kedaluwarsa atau prosesnya mati dimatikan dan port-nya dibebaskan.
Schedule::call(fn () => app(DemoServerManager::class)->prune())
    ->name('demo-prune')->everyFiveMinutes()->withoutOverlapping();
