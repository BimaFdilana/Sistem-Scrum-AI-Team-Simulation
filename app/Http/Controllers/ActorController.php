<?php

namespace App\Http\Controllers;

use App\Models\Actor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ActorController extends Controller
{
    public function index(): View
    {
        $schemaReady = Schema::hasTable('actors') && Schema::hasColumn('stories', 'actor_id');

        return view('actors.index', [
            'actors' => $schemaReady ? Actor::query()->withCount('stories')->orderBy('name')->get() : collect(),
            'schemaReady' => $schemaReady,
            'activePage' => 'actors',
        ]);
    }

    public function create(): View|RedirectResponse
    {
        if (! $this->schemaReady()) {
            return redirect()->route('actors.index');
        }

        return view('actors.form', ['actor' => new Actor, 'activePage' => 'actors']);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $this->schemaReady()) {
            return redirect()->route('actors.index')->withErrors(['actor' => 'CRUD aktor menunggu migrasi database yang belum dijalankan.']);
        }

        Actor::query()->create($this->validated($request));

        return redirect()->route('actors.index')->with('status', 'Aktor berhasil ditambahkan.');
    }

    public function edit(Actor $actor): View
    {
        return view('actors.form', ['actor' => $actor, 'activePage' => 'actors']);
    }

    public function update(Request $request, Actor $actor): RedirectResponse
    {
        $actor->update($this->validated($request, $actor));

        return redirect()->route('actors.index')->with('status', 'Aktor berhasil diperbarui.');
    }

    public function destroy(Actor $actor): RedirectResponse
    {
        if ($actor->stories()->exists()) {
            return back()->withErrors(['actor' => 'Aktor yang sudah digunakan story tidak dapat dihapus.']);
        }

        $actor->delete();

        return redirect()->route('actors.index')->with('status', 'Aktor berhasil dihapus.');
    }

    private function schemaReady(): bool
    {
        return Schema::hasTable('actors') && Schema::hasColumn('stories', 'actor_id');
    }

    /** @return array{name: string, description: ?string, is_active: bool} */
    private function validated(Request $request, ?Actor $actor = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('actors', 'name')->ignore($actor?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'name.required' => 'Nama aktor wajib diisi.',
            'name.unique' => 'Nama aktor sudah digunakan.',
        ]) + ['is_active' => $request->boolean('is_active', $actor?->is_active ?? true)];
    }
}
