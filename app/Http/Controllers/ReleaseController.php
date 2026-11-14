<?php

namespace App\Http\Controllers;

use App\Jobs\RefreshDemoJob;
use App\Models\Project;
use App\Support\Release\ReleaseManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** Demo & Rilis: satukan dengan GitHub dan kirim ke GitHub. */
class ReleaseController extends Controller
{
    public function unify(Request $request, Project $project, ReleaseManager $releases): RedirectResponse
    {
        $strategy = $request->input('strategy');
        $strategy = in_array($strategy, ['ours', 'theirs'], true) ? $strategy : null;

        try {
            $result = $releases->unify($project, $strategy);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['release' => $exception->getMessage()]);
        }
        if (! $result['ok']) {
            return back()->withErrors(['release' => $result['message']])->with('release_conflicts', $result['conflicts']);
        }
        RefreshDemoJob::dispatch($project->id);

        return back()->with('status', $result['message']);
    }

    public function push(Request $request, Project $project, ReleaseManager $releases): RedirectResponse
    {
        try {
            $release = $releases->push($project, $request->user());
        } catch (RuntimeException $exception) {
            return back()->withErrors(['release' => $exception->getMessage()]);
        }

        return $release->status === 'PUSHED'
            ? back()->with('status', 'Terkirim ke GitHub: '.$release->commit_count.' commit. Cadangan versi sebelumnya: '.$release->backup_tag.'.')
            : back()->withErrors(['release' => 'Gagal mengirim ke GitHub. '.$release->error]);
    }
}
