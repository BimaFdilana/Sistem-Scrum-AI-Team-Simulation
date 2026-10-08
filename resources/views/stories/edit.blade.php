@extends('layouts.workspace', ['title' => 'Edit User Story', 'breadcrumb' => 'Projects / '.$project->name.' / Sprint Backlog / Edit User Story'])

@section('content')
    <section class="page-heading">
        <div>
            <p class="eyebrow"><span class="eyebrow-line"></span>SPRINT BACKLOG · {{ $story->sprint?->name }}</p>
            <h1>Edit {{ $story->display_id }}</h1>
            <p class="page-subtitle">Perubahan User Story membatalkan persetujuan scope sebelumnya.</p>
        </div>
        <a class="text-link" href="{{ route('projects.sprint-backlog.index', $project) }}">← Kembali ke Sprint Backlog</a>
    </section>

    <form class="project-form story-form" method="POST" action="{{ route('stories.update', $story) }}">
        @csrf
        @method('PATCH')
        <div class="project-form-main">
            <div class="field-group"><label for="actor_id">Sebagai [Aktor] <span>*</span></label><select id="actor_id" name="actor_id" required><option value="">Pilih aktor</option>@foreach ($actors as $actor)<option value="{{ $actor->id }}" @selected((string) old('actor_id', $story->actor_id) === (string) $actor->id)>{{ $actor->name }}</option>@endforeach</select>@error('actor_id')<small class="field-error">{{ $message }}</small>@enderror</div>
            <div class="field-group"><label for="title">Saya Ingin [Fitur] <span>*</span></label><textarea id="title" name="title" rows="3" maxlength="180" required>{{ old('title', $story->title) }}</textarea>@error('title')<small class="field-error">{{ $message }}</small>@enderror</div>
            <div class="field-group"><label for="sprint_purpose">Agar [Tujuan] <span>*</span></label><textarea id="sprint_purpose" name="sprint_purpose" rows="3" maxlength="3000" required>{{ old('sprint_purpose', $story->sprint_purpose) }}</textarea>@error('sprint_purpose')<small class="field-error">{{ $message }}</small>@enderror</div>
            <div class="field-group"><label for="acceptance_criteria">Acceptance Criteria <span>*</span></label><textarea id="acceptance_criteria" name="acceptance_criteria" rows="5" maxlength="10000" required>{{ old('acceptance_criteria', $story->acceptance_criteria) }}</textarea>@error('acceptance_criteria')<small class="field-error">{{ $message }}</small>@enderror</div>
            <div class="form-actions">
                <a class="button button-ghost" href="{{ route('projects.sprint-backlog.index', $project) }}">Batal</a>
                <button class="button button-primary" type="submit">Simpan revisi <span aria-hidden="true">→</span></button>
            </div>
        </div>
        <aside class="project-form-aside">
            <p class="section-kicker">STORY ID</p>
            <h2>{{ $story->display_id }}</h2>
            <p class="section-kicker">Sprint ID: {{ $story->sprint?->name }}</p>
        </aside>
    </form>
@endsection
