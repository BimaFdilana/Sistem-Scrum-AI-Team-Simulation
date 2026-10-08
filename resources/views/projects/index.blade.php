@extends('layouts.workspace', ['title' => 'Projects', 'breadcrumb' => 'Projects'])

@section('content')
    <section class="page-heading">
        <div>
            <p class="eyebrow"><span class="eyebrow-line"></span>{{ in_array($section, ['backlog', 'sprints', 'sprint-backlog'], true) ? 'SPRINT BACKLOG' : 'PROJECT WORKSPACES' }}</p>
            <h1>{{ in_array($section, ['backlog', 'sprints', 'sprint-backlog'], true) ? 'Pilih project untuk membuka Sprint Backlog' : 'Projects' }}</h1>
            <p class="page-subtitle">{{ $section ? 'Pilih project yang Sprint Backlog-nya ingin Anda kelola.' : 'Setiap project memiliki Sprint Backlog dan source workspace tersendiri.' }}</p>
        </div>
        @if (! $section)
            <a class="button button-primary" href="{{ route('projects.create') }}">＋ Buat project</a>
        @endif
    </section>

    @if (session('status'))
        <div class="success-banner" role="status">{{ session('status') }}</div>
    @endif

    @if ($projects->isEmpty())
        <section class="project-empty">
            <span class="empty-icon">＋</span>
            <p class="section-kicker">NO PROJECTS YET</p>
            @if (! $section)
                <h2>Mulai dengan satu project</h2>
                <p>Buat workspace baru atau simpan source ZIP project existing dalam karantina privat.</p>
                <a class="button button-primary" href="{{ route('projects.create') }}">Buat project pertama</a>
            @else
                <h2>Belum ada project</h2>
                <p>Buat project melalui menu Projects untuk mulai mengisi Sprint Backlog.</p>
            @endif
        </section>
    @else
        <section class="project-list" aria-label="Daftar project">
            @foreach ($projects as $project)
                <a class="project-row" href="{{ in_array($section, ['backlog', 'sprints', 'sprint-backlog'], true) ? route('projects.sprint-backlog.index', $project) : route('projects.show', $project) }}" aria-label="{{ in_array($section, ['backlog', 'sprints', 'sprint-backlog'], true) ? 'Buka Sprint Backlog project '.$project->name : 'Buka project '.$project->name }}">
                    <span class="project-mark">{{ mb_strtoupper(mb_substr($project->name, 0, 1)) }}</span>
                    <span class="project-main"><strong>{{ $project->name }}</strong><small>{{ $project->description ?: 'Belum ada deskripsi' }}</small></span>
                    <span class="project-type">{{ $project->project_type === 'NEW_PROJECT' ? 'Project baru' : 'Existing source' }}</span>
                    <span class="project-status"><i></i>{{ str_replace('_', ' ', $project->status) }}</span>
                    <span class="project-open" aria-hidden="true">→</span>
                </a>
            @endforeach
        </section>
    @endif
@endsection
