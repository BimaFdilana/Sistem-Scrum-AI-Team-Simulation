<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Jobs\ImportGithubProjectJob;
use App\Jobs\ScaffoldLaravelProjectJob;
use App\Models\Project;
use App\Services\PublicGithubRepositoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function index(Request $request): View
    {
        $section = $request->query('section');
        $section = in_array($section, ['backlog', 'sprints', 'sprint-backlog'], true) ? $section : null;

        return view('projects.index', [
            'projects' => Project::query()->latest()->get(),
            'section' => $section,
            'activePage' => $section === 'sprint-backlog' ? 'backlog' : ($section ?? 'projects'),
        ]);
    }

    public function create(): View
    {
        return view('projects.create', ['activePage' => 'projects']);
    }

    public function store(StoreProjectRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $githubUrl = blank($data['github_repository_url'] ?? null) ? null : PublicGithubRepositoryService::normalizeUrl($data['github_repository_url']);

        $project = DB::transaction(function () use ($data, $githubUrl): Project {
            return Project::query()->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'project_type' => $data['project_type'],
                'source_repository_url' => $githubUrl,
                'source_branch' => $data['github_branch'] ?? null,
                'stack' => $githubUrl ? null : ($data['stack'] ?? 'Laravel'),
                // Tanpa upload ZIP (keputusan Bima): project baru = scaffold Laravel
                // asli di background; import GitHub = clone + verifikasi di background.
                'status' => $githubUrl ? 'SOURCE_VERIFYING' : 'SCAFFOLDING',
            ]);
        });

        if ($githubUrl) {
            ImportGithubProjectJob::dispatch($project->id);
        } else {
            ScaffoldLaravelProjectJob::dispatch($project->id);
        }

        return redirect()
            ->route('projects.show', $project)
            ->with('status', $githubUrl
                ? 'Repository GitHub diterima dan sedang diverifikasi di latar belakang.'
                : 'Project Laravel baru sedang dibuat di latar belakang.');
    }

    public function show(Project $project): View
    {
        return view('projects.show', [
            'project' => $project->loadCount('stories'),
            'activePage' => 'projects',
        ]);
    }
}
