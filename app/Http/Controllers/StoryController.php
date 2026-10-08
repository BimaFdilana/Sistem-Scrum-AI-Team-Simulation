<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStoryRequest;
use App\Models\Actor;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Story;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class StoryController extends Controller
{
    public function index(Project $project): View
    {
        if (! Schema::hasTable('backlogs') || ! Schema::hasColumn('sprints', 'backlog_id') || ! Schema::hasColumn('stories', 'user_story_key')) {
            return view('stories.migration-pending', ['project' => $project, 'activePage' => 'backlog']);
        }
        $backlog = $project->backlog;
        $estimationSchemaReady = Schema::hasTable('story_estimation_runs')
            && Schema::hasTable('story_estimation_agent_runs');
        $storyRelations = ['stories.actor'];
        if ($estimationSchemaReady) {
            $storyRelations[] = 'stories.estimationRuns.agentRuns';
        }

        return view('stories.index', [
            'project' => $project->load('backlog'),
            'backlog' => $backlog,
            'sprints' => $backlog->sprints()->withCount('stories')->with($storyRelations)->latest()->get(),
            'actors' => Actor::query()->where('is_active', true)->orderBy('name')->get(),
            'sprintSchemaReady' => DB::table('migrations')->where('migration', '2026_10_08_000001_make_sprint_goal_and_legacy_dates_optional')->exists(),
            'estimationSchemaReady' => $estimationSchemaReady,
            'activePage' => 'backlog',
        ]);
    }

    public function updateBacklog(Request $request, Project $project): RedirectResponse
    {
        if (! Schema::hasTable('backlogs')) {
            return redirect()->route('projects.sprint-backlog.index', $project)
                ->withErrors(['database' => 'Perubahan menunggu migrasi database backlog yang belum dijalankan. Tidak ada data yang diubah.']);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'],
        ], [
            'name.required' => 'Nama backlog wajib diisi.',
        ]);

        $project->backlog()->updateOrCreate([], $data);

        return redirect()->route('projects.sprint-backlog.index', $project)->with('status', 'Backlog project diperbarui.');
    }

    public function createForSprint(Sprint $sprint): View|RedirectResponse
    {
        if (! Schema::hasTable('backlogs') || ! Schema::hasColumn('sprints', 'backlog_id') || ! Schema::hasColumn('stories', 'user_story_key')) {
            return redirect()->route('projects.sprint-backlog.index', $sprint->project_id)
                ->withErrors(['database' => 'Penambahan user story menunggu migrasi backlog yang belum dijalankan. Tidak ada data yang diubah.']);
        }

        if ($sprint->status !== 'PLANNING') {
            return redirect()->route('sprints.show', $sprint)->withErrors(['story' => 'User story hanya dapat ditambahkan saat Sprint berstatus PLANNING.']);
        }

        return view('stories.create', [
            'sprint' => $sprint->load('project'),
            'project' => $sprint->project,
            'actors' => Actor::query()->where('is_active', true)->orderBy('name')->get(),
            'activePage' => 'backlog',
        ]);
    }

    public function storeForSprint(StoreStoryRequest $request, Sprint $sprint): RedirectResponse
    {
        if (! Schema::hasTable('backlogs') || ! Schema::hasColumn('sprints', 'backlog_id') || ! Schema::hasColumn('stories', 'user_story_key')) {
            return redirect()->route('projects.sprint-backlog.index', $sprint->project_id)
                ->withErrors(['database' => 'Penyimpanan user story menunggu migrasi backlog yang belum dijalankan. Tidak ada data yang diubah.']);
        }

        if ($sprint->status !== 'PLANNING') {
            return redirect()->route('sprints.show', $sprint)->withErrors(['story' => 'User story hanya dapat ditambahkan saat Sprint berstatus PLANNING.']);
        }

        if (! Schema::hasTable('actors') || ! Schema::hasColumn('stories', 'actor_id')) {
            return back()->withErrors(['database' => 'Form baru menunggu migrasi database yang belum dijalankan. Tidak ada data yang diubah.'])->withInput();
        }

        DB::transaction(function () use ($request, $sprint): void {
            $project = $sprint->project()->lockForUpdate()->firstOrFail();
            $lastKey = $project->stories()->orderByDesc('id')->value('user_story_key');
            $lastNumber = preg_match('/^US-(\d+)$/', (string) $lastKey, $matches) ? (int) $matches[1] : 0;
            $number = $lastNumber + 1;

            $project->stories()->create([
                ...$request->validated(),
                'sprint_id' => $sprint->id,
                'user_story_key' => 'US-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT),
                'scope_status' => 'NEEDS_REFINEMENT',
                'revision' => 1,
            ]);
            $sprint->forceFill([
                'revision' => $sprint->revision + 1,
                'scope_approved_revision' => null,
                'scope_approved_by' => null,
                'scope_approved_at' => null,
            ])->save();
        });

        return redirect()->route('projects.sprint-backlog.index', $sprint->project_id)->with('status', 'User story ditambahkan ke '.$sprint->name.'.');
    }

    public function edit(Story $story): View|RedirectResponse
    {
        if ($story->sprint()->where('status', 'ACTIVE')->exists()) {
            return redirect()->route('projects.sprint-backlog.index', $story->project_id)
                ->withErrors(['story' => 'Story dalam sprint aktif dikunci sampai ada workflow perubahan scope.']);
        }

        return view('stories.edit', [
            'story' => $story,
            'project' => $story->project,
            'activePage' => 'backlog',
        ]);
    }

    public function update(StoreStoryRequest $request, Story $story): RedirectResponse
    {
        if ($story->sprint()->where('status', 'ACTIVE')->exists()) {
            return redirect()->route('projects.sprint-backlog.index', $story->project_id)
                ->withErrors(['story' => 'Story dalam sprint aktif dikunci sampai ada workflow perubahan scope.']);
        }

        if (! $story->sprint_id) {
            return redirect()->route('projects.sprint-backlog.index', $story->project_id)
                ->withErrors(['story' => 'User Story harus berada di dalam Sprint.']);
        }

        $currentRevision = $story->revision;
        $story->fill($request->validated());

        if ($story->isDirty(['title', 'actor_id', 'sprint_purpose', 'acceptance_criteria'])) {
            $story->revision = $currentRevision + 1;
            $story->estimate_points = null;
            $story->scope_status = 'NEEDS_REFINEMENT';
            $story->scope_approved_revision = null;
            $story->scope_approved_by = null;
            $story->scope_approved_at = null;
            $story->save();

            $story->sprint()->update([
                'revision' => DB::raw('revision + 1'),
                'scope_approved_revision' => null,
                'scope_approved_by' => null,
                'scope_approved_at' => null,
            ]);

            return redirect()->route('projects.sprint-backlog.index', $story->project_id)
                ->with('status', 'Story diperbarui. Approval scope sebelumnya sudah tidak berlaku.');
        }

        return redirect()->route('projects.sprint-backlog.index', $story->project_id)->with('status', 'Tidak ada perubahan pada story.');
    }
}
