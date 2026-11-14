<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Support\Demo\DemoContents;
use App\Support\Demo\DemoServerManager;
use App\Support\Execution\ProjectRuns;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Halaman per project di sidebar: Eksekusi, Demo & Rilis, Pengaturan Project. */
class ProjectPagesController extends Controller
{
    public function executions(Request $request, Project $project): View
    {
        $all = ProjectRuns::for($project);
        $counts = collect(ProjectRuns::GROUPS)->map(fn ($label, $key) => $all->where('group', $key)->count());
        $filter = (string) $request->query('filter', $counts['decision'] > 0 ? 'decision' : 'all');
        if ($filter !== 'all' && ! array_key_exists($filter, ProjectRuns::GROUPS)) {
            $filter = 'all';
        }

        return view('projects.executions', [
            'project' => $project,
            'rows' => $filter === 'all' ? $all : $all->where('group', $filter)->values(),
            'counts' => $counts,
            'total' => $all->count(),
            'filter' => $filter,
            'activePage' => 'executions',
        ]);
    }

    public function release(Project $project, DemoServerManager $demos): View
    {
        $demo = $demos->runningFor($project);

        return view('projects.release', [
            'project' => $project,
            'demo' => $demo,
            'demoUrl' => $demo ? $demos->url($demo, request()->getHost()) : null,
            'demoContents' => DemoContents::for($project),
            'activePage' => 'release',
        ]);
    }

    public function settings(Project $project): View
    {
        return view('projects.settings', [
            'project' => $project,
            'backlog' => $project->backlog,
            'activePage' => 'project-settings',
        ]);
    }
}
