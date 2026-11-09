<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Watchdog: task/run yang menggantung ditandai agar bisa ditindak PO.
Schedule::command('runs:watchdog')->everyFiveMinutes()->withoutOverlapping();
