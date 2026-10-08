<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSprintRequest;
use App\Http\Requests\UpdateSprintScopeRequest;
use App\Models\Actor;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Story;
use App\Services\HermesClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SprintController extends Controller
{
    public function index(Project $project): RedirectResponse
    {
        return redirect()->route('projects.sprint-backlog.index', $project);
    }

    public function create(Project $project): RedirectResponse
    {
        return redirect()->route('projects.sprint-backlog.index', $project);
    }

    public function store(StoreSprintRequest $request, Project $project): RedirectResponse
    {
        if (! Schema::hasTable('backlogs') || ! Schema::hasColumn('sprints', 'backlog_id')) {
            return redirect()->route('projects.stories.index', $project)
                ->withErrors(['database' => 'Pembuatan Sprint menunggu migrasi backlog yang belum dijalankan. Tidak ada data yang diubah.']);
        }

        if (! DB::table('migrations')->where('migration', '2026_10_08_000001_make_sprint_goal_and_legacy_dates_optional')->exists()) {
            return redirect()->route('projects.sprint-backlog.index', $project)
                ->withErrors(['database' => 'Pembuatan Sprint menunggu migrasi Sprint Backlog. Data tidak diubah.']);
        }

        $backlog = $project->backlog()->firstOrCreate([], [
            'name' => $project->name.' Backlog',
            'description' => null,
        ]);

        $sprint = $backlog->sprints()->create([
            'project_id' => $project->id,
            ...$request->safe()->only(['name']),
            'goal_source' => 'PENDING',
            'status' => 'PLANNING',
            'revision' => 1,
        ]);

        return redirect()->route('projects.sprint-backlog.index', $project)->with('status', 'Sprint dibuat. Tambahkan User Story ke dalam '.$sprint->name.'.');
    }

    public function generateGoal(Request $request, Sprint $sprint, HermesClient $hermes): RedirectResponse
    {
        if (! DB::table('migrations')->where('migration', '2026_10_08_000001_make_sprint_goal_and_legacy_dates_optional')->exists()) {
            return back()->withErrors(['goal' => 'Pembuatan Sprint Goal menunggu migrasi Sprint Backlog. Data tidak diubah.']);
        }

        if ($sprint->status !== 'PLANNING') {
            return back()->withErrors(['goal' => 'Sprint Goal hanya dapat dirumuskan saat Sprint masih dalam planning.']);
        }

        $stories = $sprint->stories()->with('actor')->orderBy('id')->get();
        if ($stories->isEmpty()) {
            return back()->withErrors(['goal' => 'Tambahkan User Story sebelum meminta Scrum Master AI merumuskan Sprint Goal.']);
        }

        $summary = $stories->map(fn (Story $story): string => implode("\n", [
            $story->display_id.' — '.$story->title,
            'Aktor: '.($story->actor?->name ?? 'belum dipilih'),
            'Tujuan: '.($story->sprint_purpose ?? ''),
            'Acceptance Criteria: '.($story->acceptance_criteria ?? ''),
        ]))->implode("\n\n");
        $prompt = "Sebagai Scrum Master, rumuskan satu Sprint Goal yang ringkas, berorientasi hasil, dan mencakup User Story berikut. Kembalikan hanya satu kalimat Sprint Goal, tanpa judul atau penjelasan tambahan.\n\nSprint: {$sprint->name}\nUser Stories:\n{$summary}";

        try {
            $goal = trim($hermes->sendMessage($prompt, 'sprint-goal-'.$sprint->id.'-'.$sprint->revision));
        } catch (\RuntimeException) {
            return back()->withErrors(['goal' => 'Scrum Master AI belum dapat dihubungi. Sprint Goal belum berubah.']);
        }

        if (mb_strlen($goal) > 2000) {
            return back()->withErrors(['goal' => 'Hasil Scrum Master AI terlalu panjang. Sprint Goal belum berubah.']);
        }

        $sprint->forceFill([
            'goal' => $goal,
            'goal_source' => 'AI_DRAFT',
            'revision' => $sprint->revision + 1,
            'scope_approved_revision' => null,
            'scope_approved_by' => null,
            'scope_approved_at' => null,
        ])->save();

        return back()->with('status', 'Draf Sprint Goal dari Scrum Master AI dibuat. Tinjau User Story dan setujui scope sebelum Sprint dimulai.');
    }

    public function show(Sprint $sprint): View
    {
        $backlogReady = Schema::hasTable('backlogs') && Schema::hasColumn('sprints', 'backlog_id');
        $detailsReady = $this->detailsSchemaReady();
        $sprintSchemaReady = DB::table('migrations')->where('migration', '2026_10_08_000001_make_sprint_goal_and_legacy_dates_optional')->exists();
        $sprint->load('project');
        $sprint->load($detailsReady ? ['stories.actor'] : ['stories']);
        $availableStories = $detailsReady ? $sprint->stories()->orderBy('id')->get() : collect();
        $actors = $detailsReady ? Actor::query()->where('is_active', true)->orderBy('name')->get() : collect();

        return view('sprints.show', [
            'sprint' => $sprint,
            'availableStories' => $availableStories,
            'actors' => $actors,
            'detailsReady' => $detailsReady,
            'backlogReady' => $backlogReady,
            'sprintSchemaReady' => $sprintSchemaReady,
            'activePage' => 'sprints',
        ]);
    }

    public function updateScope(UpdateSprintScopeRequest $request, Sprint $sprint): RedirectResponse
    {
        if (! $this->detailsSchemaReady()) {
            return back()->withErrors(['scope' => 'Form Sprint baru menunggu migrasi database yang belum dijalankan.']);
        }

        if ($sprint->status !== 'PLANNING') {
            return back()->withErrors(['scope' => 'Scope hanya dapat diubah selama sprint berstatus PLANNING.']);
        }

        $storyIds = array_map('intval', $request->validated('story_ids', []));
        $currentStoryIds = $sprint->stories()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $submittedStoryIds = $storyIds;
        sort($currentStoryIds);
        sort($submittedStoryIds);

        if ($submittedStoryIds !== $currentStoryIds) {
            return back()->withErrors(['scope' => 'User story tetap menjadi bagian Sprint ini. Tambahkan story melalui halaman Sprint dan ubah hanya rincian scope-nya.']);
        }

        $eligibleCount = Story::query()
            ->whereIn('id', $storyIds)
            ->where('project_id', $sprint->project_id)
            ->where('sprint_id', $sprint->id)
            ->count();

        if ($eligibleCount !== count($storyIds)) {
            return back()->withErrors(['scope' => 'Pilih hanya story dari project ini yang belum berada di Sprint lain.']);
        }

        $input = ['story_details' => $request->input('story_details', [])];
        $rules = ['story_details' => ['array']];
        foreach ($storyIds as $storyId) {
            $prefix = 'story_details.'.$storyId;
            $rules[$prefix.'.actor_id'] = ['required', 'integer', Rule::exists('actors', 'id')->where('is_active', true)];
            $rules[$prefix.'.purpose'] = ['required', 'string', 'min:3', 'max:3000'];
            $rules[$prefix.'.acceptance_criteria'] = ['required', 'string', 'min:10', 'max:10000'];
        }
        $validator = Validator::make($input, $rules, [
            'story_details.*.actor_id.required' => 'Pilih aktor untuk setiap story yang dimasukkan ke Sprint.',
            'story_details.*.purpose.required' => 'Tujuan wajib diisi untuk setiap story yang dipilih.',
            'story_details.*.acceptance_criteria.required' => 'Acceptance criteria wajib diisi untuk setiap story yang dipilih.',
            'story_details.*.acceptance_criteria.min' => 'Acceptance criteria harus menjelaskan perilaku yang dapat diperiksa.',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $details = $validator->validated()['story_details'] ?? [];

        DB::transaction(function () use ($sprint, $storyIds, $details): void {
            $removedStories = $sprint->stories()->when($storyIds !== [], fn ($query) => $query->whereNotIn('id', $storyIds))->get();
            foreach ($removedStories as $story) {
                $story->forceFill([
                    'sprint_id' => null,
                    'actor_id' => null,
                    'sprint_purpose' => null,
                    'acceptance_criteria' => null,
                    'sprint_story_status' => 'NOT_STARTED',
                    'scope_status' => 'NEEDS_REFINEMENT',
                    'scope_approved_revision' => null,
                    'scope_approved_by' => null,
                    'scope_approved_at' => null,
                    'revision' => $story->revision + 1,
                ])->save();
            }

            foreach ($storyIds as $storyId) {
                $story = Story::query()->whereKey($storyId)->firstOrFail();
                $story->forceFill([
                    'sprint_id' => $sprint->id,
                    'actor_id' => $details[$storyId]['actor_id'],
                    'sprint_purpose' => $details[$storyId]['purpose'],
                    'acceptance_criteria' => $details[$storyId]['acceptance_criteria'],
                    'sprint_story_status' => 'NOT_STARTED',
                    'scope_status' => 'NEEDS_REFINEMENT',
                    'scope_approved_revision' => null,
                    'scope_approved_by' => null,
                    'scope_approved_at' => null,
                    'revision' => $story->revision + 1,
                ])->save();
            }

            $sprint->forceFill([
                'revision' => $sprint->revision + 1,
                'scope_approved_revision' => null,
                'scope_approved_by' => null,
                'scope_approved_at' => null,
            ])->save();
        });

        return redirect()
            ->route('sprints.show', $sprint)
            ->with('status', 'Rincian Sprint diperbarui. PO perlu menyetujui scope terbaru.');
    }

    public function approveScope(Request $request, Sprint $sprint): RedirectResponse
    {
        if (! DB::table('migrations')->where('migration', '2026_10_08_000001_make_sprint_goal_and_legacy_dates_optional')->exists()) {
            return back()->withErrors(['scope' => 'Persetujuan menunggu migrasi Sprint Backlog. Data tidak diubah.']);
        }

        if (! $this->detailsSchemaReady()) {
            return back()->withErrors(['scope' => 'Persetujuan scope baru menunggu migrasi database yang belum dijalankan.']);
        }

        if ($sprint->status !== 'PLANNING' || $sprint->stories()->count() === 0) {
            return back()->withErrors(['scope' => 'Sprint harus berada di PLANNING dan memiliki minimal satu story untuk disetujui.']);
        }

        if (blank($sprint->goal) || $sprint->goal_source !== 'AI_DRAFT') {
            return back()->withErrors(['scope' => 'Buat Sprint Goal dengan Scrum Master AI sebelum menyetujui Sprint.']);
        }

        if ($this->hasIncompleteStoryDetails($sprint)) {
            return back()->withErrors(['scope' => 'Setiap story harus memiliki aktor aktif, tujuan, dan acceptance criteria yang lengkap.']);
        }

        DB::transaction(function () use ($request, $sprint): void {
            foreach ($sprint->stories as $story) {
                $story->forceFill([
                    'scope_status' => 'APPROVED',
                    'scope_approved_revision' => $story->revision,
                    'scope_approved_by' => $request->user()->id,
                    'scope_approved_at' => now(),
                ])->save();
            }

            $sprint->forceFill([
                'scope_approved_revision' => $sprint->revision,
                'scope_approved_by' => $request->user()->id,
                'scope_approved_at' => now(),
            ])->save();
        });

        return redirect()
            ->route('sprints.show', $sprint)
            ->with('status', 'Sprint scope disetujui untuk revision '.$sprint->revision.'.');
    }

    public function start(Request $request, Sprint $sprint): RedirectResponse
    {
        if (! $this->detailsSchemaReady()) {
            return back()->withErrors(['start' => 'Sprint belum dapat dimulai sampai migrasi database disetujui dan dijalankan.']);
        }

        if ($sprint->status !== 'PLANNING'
            || $sprint->stories()->count() === 0
            || $sprint->scope_approved_revision !== $sprint->revision
            || $this->hasUnapprovedStoryScope($sprint)) {
            return back()->withErrors(['start' => 'Sprint hanya dapat dimulai setelah scope revision saat ini disetujui Product Owner.']);
        }

        $sprint->forceFill([
            'status' => 'ACTIVE',
            'started_at' => now(),
        ])->save();

        return redirect()
            ->route('sprints.show', $sprint)
            ->with('status', 'Sprint dimulai. Estimasi agent dan task execution belum aktif.');
    }

    private function detailsSchemaReady(): bool
    {
        return Schema::hasTable('actors') && Schema::hasColumn('stories', 'actor_id');
    }

    private function hasIncompleteStoryDetails(Sprint $sprint): bool
    {
        return $sprint->stories()->where(function ($query): void {
            $query->where(function ($details): void {
                $details->whereNull('actor_id')
                    ->orWhereNull('sprint_purpose')
                    ->orWhere('sprint_purpose', '')
                    ->orWhereNull('acceptance_criteria')
                    ->orWhere('acceptance_criteria', '');
            })->orWhereDoesntHave('actor', fn ($actorQuery) => $actorQuery->where('is_active', true));
        })->exists();
    }

    private function hasUnapprovedStoryScope(Sprint $sprint): bool
    {
        return $sprint->stories()->where(function ($query): void {
            $query->where('scope_status', '!=', 'APPROVED')
                ->orWhereNull('scope_approved_revision')
                ->orWhereColumn('scope_approved_revision', '!=', 'revision');
        })->exists();
    }
}
