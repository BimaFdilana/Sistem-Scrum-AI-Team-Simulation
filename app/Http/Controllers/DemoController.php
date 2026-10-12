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

    public function stop(Project $project, DemoServerManager $demos): RedirectResponse
    {
        $demo = $demos->runningFor($project);
        if ($demo) {
            $demos->stop($demo);
        }

        return back()->with('status', 'Demo dihentikan.');
    }
}
