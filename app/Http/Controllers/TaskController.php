<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTaskRequest;
use App\Models\Story;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class TaskController extends Controller
{
    public function index(Story $story): View
    {
        $story->load(['project', 'sprint']);

        return view('tasks.index', [
            'story' => $story,
            'tasks' => $story->tasks()->latest()->get(),
            'scopeApproved' => $story->scope_status === 'APPROVED'
                && $story->scope_approved_revision === $story->revision,
            'activePage' => 'projects',
        ]);
    }

    public function create(Story $story): View|RedirectResponse
    {
        if ($story->scope_status !== 'APPROVED' || $story->scope_approved_revision !== $story->revision) {
            return redirect()
                ->route('stories.tasks.index', $story)
                ->withErrors(['scope' => 'Task baru memerlukan scope story yang disetujui untuk revision saat ini.']);
        }

        return view('tasks.create', [
            'story' => $story->load('project'),
            'activePage' => 'projects',
        ]);
    }

    public function store(StoreTaskRequest $request, Story $story): RedirectResponse
    {
        if ($story->scope_status !== 'APPROVED' || $story->scope_approved_revision !== $story->revision) {
            return redirect()
                ->route('stories.tasks.index', $story)
                ->withErrors(['scope' => 'Task baru memerlukan scope story yang disetujui untuk revision saat ini.']);
        }

        $story->tasks()->create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
            'status' => 'BACKLOG',
            'story_revision' => $story->revision,
            'revision' => 1,
        ]);

        return redirect()
            ->route('stories.tasks.index', $story)
            ->with('status', 'Task ditambahkan ke backlog. Agent belum dapat mengeksekusinya.');
    }
}
