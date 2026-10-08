@extends('layouts.workspace', ['title' => $project->name, 'breadcrumb' => 'Projects / '.$project->name])

@section('content')
    <section class="page-heading">
        <div>
            <p class="eyebrow"><span class="eyebrow-line"></span>PROJECT OVERVIEW</p>
            <h1>{{ $project->name }}</h1>
            <p class="page-subtitle">{{ $project->description ?: 'Belum ada deskripsi project.' }}</p>
        </div>
        <a class="text-link" href="{{ route('projects.index') }}">← Semua projects</a>
    </section>

    @if (session('status'))
        <div class="success-banner" role="status">{{ session('status') }}</div>
    @endif

    <section class="project-detail-grid">
        <article class="empty-panel">
            <div class="panel-heading"><div><p class="section-kicker">PROJECT TYPE</p><h2>{{ $project->project_type === 'NEW_PROJECT' ? 'Project baru' : 'Project existing' }}</h2></div><span class="soft-badge">{{ str_replace('_', ' ', $project->status) }}</span></div>
            <p>{{ $project->stack ? 'Stack awal: '.$project->stack : 'Stack belum ditentukan.' }}</p>
            <div class="approval-rule"><span class="rule-check">✓</span><span>Belum ada agent yang diberi izin mengubah source project ini.</span></div>
        </article>
        <article class="empty-panel">
            <div class="panel-heading"><div><p class="section-kicker">SOURCE BASELINE</p><h2>Source archive</h2></div><span class="soft-badge">Private</span></div>
            @forelse ($project->sourceArchives as $archive)
                <p class="archive-name">{{ $archive->original_name }}</p>
                <div class="archive-meta"><span>{{ number_format($archive->size_bytes / 1048576, 2) }} MB</span><span>SHA-256 <code>{{ $archive->sha256 }}</code></span></div>
                <div class="approval-rule"><span class="status-dot status-idle"></span><span>Status {{ str_replace('_', ' ', $archive->status) }} · belum diekstrak atau dijalankan</span></div>
            @empty
                <p>Belum ada source archive. Project baru belum memiliki repository/worktree.</p>
            @endforelse
        </article>
    </section>

    <section class="notice-bar project-gate"><span class="notice-icon">i</span><p><strong>Agent execution masih terkunci.</strong> Isolasi Git worktree per task belum diverifikasi. Chat AI Agent tidak memberi izin kepada agent untuk mengubah source project.</p></section>

    <section class="backlog-launch empty-panel">
        <div class="panel-heading"><div><p class="section-kicker">SPRINT BACKLOG</p><h2>{{ $project->stories_count }} User Story</h2></div><span class="soft-badge">PO approval required</span></div>
        <p>Satu Sprint Backlog untuk project ini. Buat beberapa Sprint dan susun User Story di dalam Sprint masing-masing.</p>
        <a class="button button-primary" href="{{ route('projects.sprint-backlog.index', $project) }}">Buka Sprint Backlog <span aria-hidden="true">→</span></a>
    </section>
@endsection
