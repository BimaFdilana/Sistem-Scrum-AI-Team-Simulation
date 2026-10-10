<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Jobs\ImportGithubProjectJob;
use App\Models\Project;
use App\Services\PublicGithubRepositoryService;
use App\Support\Archives\ZipArchiveInspector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class ProjectController extends Controller
{
    public function index(Request $request): View
    {
        $section = $request->query('section');
        $section = in_array($section, ['backlog', 'sprints', 'sprint-backlog'], true) ? $section : null;

        return view('projects.index', [
            'projects' => Project::query()->with('sourceArchives')->latest()->get(),
            'section' => $section,
            'activePage' => $section === 'sprint-backlog' ? 'backlog' : ($section ?? 'projects'),
        ]);
    }

    public function create(): View
    {
        return view('projects.create', ['activePage' => 'projects']);
    }

    public function store(StoreProjectRequest $request, ZipArchiveInspector $zipInspector): RedirectResponse
    {
        $data = $request->validated();
        $archiveFile = $request->file('source_archive');
        $githubUrl = blank($data['github_repository_url'] ?? null) ? null : PublicGithubRepositoryService::normalizeUrl($data['github_repository_url']);
        $storedPath = null;

        try {
            $project = DB::transaction(function () use ($data, $archiveFile, $githubUrl, $zipInspector, &$storedPath): Project {
                $isExisting = $data['project_type'] === 'EXISTING_PROJECT';
                $project = Project::query()->create([
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'project_type' => $data['project_type'],
                    'source_repository_url' => $githubUrl,
                    'source_branch' => $data['github_branch'] ?? null,
                    'stack' => $githubUrl ? null : ($data['stack'] ?? null),
                    'status' => $githubUrl ? 'SOURCE_VERIFYING' : ($isExisting ? 'SOURCE_QUARANTINED' : 'SETUP_REQUIRED'),
                ]);

                if ($archiveFile) {
                    $zipInspector->assertSafeMetadata($archiveFile->getRealPath());
                    $storedPath = $archiveFile->storeAs(
                        "source-archives/{$project->id}",
                        Str::uuid().'.zip',
                        'local',
                    );

                    if (! is_string($storedPath)) {
                        throw new RuntimeException('Private storage menolak penyimpanan arsip.');
                    }

                    $project->sourceArchives()->create([
                        'storage_path' => $storedPath,
                        'original_name' => $archiveFile->getClientOriginalName(),
                        'size_bytes' => $archiveFile->getSize(),
                        'sha256' => hash_file('sha256', $archiveFile->getRealPath()),
                        'status' => 'QUARANTINED',
                    ]);
                }

                return $project;
            });
        } catch (Throwable $exception) {
            if ($storedPath) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $exception;
        }

        if ($project->status === 'SOURCE_VERIFYING') {
            ImportGithubProjectJob::dispatch($project->id);
        }

        return redirect()
            ->route('projects.show', $project)
            ->with('status', $project->status === 'SOURCE_VERIFYING'
                ? 'Repository GitHub diterima dan sedang diverifikasi di latar belakang. Source belum dieksekusi.'
                : 'Project tersimpan. Source existing tetap dikarantina dan belum dieksekusi.');
    }

    public function show(Project $project): View
    {
        return view('projects.show', [
            'project' => $project->load('sourceArchives')->loadCount('stories'),
            'activePage' => 'projects',
        ]);
    }
}
