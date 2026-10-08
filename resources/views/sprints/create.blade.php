@extends('layouts.workspace', ['title' => 'Sprint Backlog · '.$project->name, 'breadcrumb' => 'Projects / '.$project->name.' / Sprint Backlog'])

@section('content')
    <section class="page-heading">
        <div>
            <p class="eyebrow"><span class="eyebrow-line"></span>SPRINT SETUP</p>
            <h1>Buat Sprint</h1>
            <p class="page-subtitle">Sprint baru akan dimasukkan ke Sprint Backlog {{ $project->backlog?->name }}.</p>
        </div>
        <a class="text-link" href="{{ route('projects.sprint-backlog.index', $project) }}">← Kembali ke Sprint Backlog</a>
    </section>
    @if ($sprintSchemaReady)
    <form class="project-form" method="POST" action="{{ route('projects.sprints.store', $project) }}">
        @csrf
        <div class="project-form-main">
            <div class="field-group"><label for="name">Nama Sprint <span>*</span></label><input id="name" name="name" value="{{ old('name') }}" required maxlength="120" placeholder="Contoh: Sprint 1">@error('name')<small class="field-error">{{ $message }}</small>@enderror</div>
            <p class="field-help">Sprint Goal dirumuskan Scrum Master AI setelah User Story ditambahkan. Tanggal pembuatan memakai waktu otomatis Laravel.</p>
            <div class="form-actions"><a class="button button-ghost" href="{{ route('projects.sprint-backlog.index', $project) }}">Batal</a><button class="button button-primary" type="submit">Simpan Sprint →</button></div>
        </div>
    </form>
    @else
        <section class="notice-bar"><span class="notice-icon">i</span><p><strong>Menunggu migrasi database Sprint Backlog.</strong> Form Sprint aktif setelah migrasi diterapkan. Data tidak berubah.</p></section>
    @endif
@endsection
