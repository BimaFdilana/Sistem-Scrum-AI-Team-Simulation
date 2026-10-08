@extends('layouts.workspace', ['title' => 'Tambah User Story', 'breadcrumb' => 'Projects / '.$project->name.' / Sprint Backlog / '.$sprint->name])

@section('content')
    <section class="page-heading">
        <div><p class="eyebrow"><span class="eyebrow-line"></span>SPRINT BACKLOG · {{ $sprint->name }}</p><h1>Tambah User Story</h1><p class="page-subtitle">Story masuk langsung ke {{ $sprint->name }}. Story ID dibuat otomatis saat disimpan.</p></div>
        <a class="text-link" href="{{ route('projects.sprint-backlog.index', $project) }}">← Kembali ke Sprint Backlog</a>
    </section>
    @error('database')<div class="form-alert" role="alert">{{ $message }}</div>@enderror
    @error('story')<div class="form-alert" role="alert">{{ $message }}</div>@enderror
    <form class="project-form story-form" method="POST" action="{{ route('sprints.stories.store', $sprint) }}">
        @csrf
        <div class="project-form-main">
            <div class="field-group"><label>Sprint ID</label><input value="{{ $sprint->name }}" readonly></div>
            <div class="field-group"><label>Story ID</label><input value="Dibuat otomatis (US-01, US-02, …)" readonly></div>
            <div class="field-group"><label for="actor_id">Sebagai [Aktor] <span>*</span></label><select id="actor_id" name="actor_id" required><option value="">Pilih aktor</option>@foreach ($actors as $actor)<option value="{{ $actor->id }}" @selected((string) old('actor_id') === (string) $actor->id)>{{ $actor->name }}</option>@endforeach</select>@error('actor_id')<small class="field-error">{{ $message }}</small>@enderror</div>
            <div class="field-group"><label for="title">Saya Ingin [Fitur] <span>*</span></label><textarea id="title" name="title" rows="3" maxlength="180" required>{{ old('title') }}</textarea>@error('title')<small class="field-error">{{ $message }}</small>@enderror</div>
            <div class="field-group"><label for="sprint_purpose">Agar [Tujuan] <span>*</span></label><textarea id="sprint_purpose" name="sprint_purpose" rows="3" maxlength="3000" required>{{ old('sprint_purpose') }}</textarea>@error('sprint_purpose')<small class="field-error">{{ $message }}</small>@enderror</div>
            <div class="field-group"><label for="acceptance_criteria">Acceptance Criteria <span>*</span></label><textarea id="acceptance_criteria" name="acceptance_criteria" rows="5" maxlength="10000" required>{{ old('acceptance_criteria') }}</textarea><small class="field-help">Tuliskan kriteria yang dapat diperiksa. Satu kriteria per baris disarankan.</small>@error('acceptance_criteria')<small class="field-error">{{ $message }}</small>@enderror</div>
            <div class="form-actions"><a class="button button-ghost" href="{{ route('projects.sprint-backlog.index', $project) }}">Batal</a><button class="button button-primary" type="submit">Simpan User Story →</button></div>
        </div>
    </form>
@endsection
