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
            <div class="panel-heading"><div><p class="section-kicker">SOURCE BASELINE</p><h2>{{ $project->source_repository_url ? 'GitHub repository' : 'Laravel baru' }}</h2></div><span class="soft-badge">{{ $project->source_repository_url ? 'Public · private baseline' : 'Scaffold otomatis' }}</span></div>
            @if ($project->source_repository_url)
                <p class="archive-name">{{ $project->source_repository_url }}</p>
                <div class="archive-meta"><span>Branch: {{ $project->source_branch ?: 'default branch' }}</span><span>Commit: <code>{{ $project->source_commit_sha ?: 'pending' }}</code></span></div>
            @else
                <p class="archive-name">Kerangka Laravel baru dibuat otomatis oleh sistem.</p>
            @endif
            @if ($project->source_error)
                <div class="approval-rule"><span class="status-dot status-danger"></span><span>{{ $project->source_error }}</span></div>
            @else
                <div class="approval-rule"><span class="status-dot status-idle"></span><span>Status {{ str_replace('_', ' ', $project->status) }} · tidak ada kode yang dijalankan</span></div>
            @endif
        </article>
    </section>

    <section class="notice-bar project-gate"><span class="notice-icon">i</span><p><strong>Demo &amp; unduhan project.</strong> Jalankan demo sementara untuk melihat hasil, atau unduh workspace sebagai ZIP.</p></section>

    <section class="empty-panel">
        <div class="panel-heading"><div><p class="section-kicker">DEMO PROJECT</p><h2>Pratinjau sementara</h2></div>
        @if ($demo)
            <span class="soft-badge">Berjalan · port {{ $demo->port }}</span>
        @endif
        </div>
        @if ($demo && $demoUrl)
            <p>Demo aktif di <a href="{{ $demoUrl }}" target="_blank" rel="noopener">{{ $demoUrl }}</a> · berhenti otomatis {{ $demo->expires_at?->format('H:i') }}.</p>
            <form method="POST" action="{{ route('projects.demo.stop', $project) }}">@csrf<button class="button button-ghost" type="submit">Hentikan demo</button></form>
            <form method="POST" action="{{ route('projects.demo.request-fix', $project) }}" style="margin-top:8px">@csrf<button class="button button-ghost" type="submit" onclick="return handleConfirm(event, {title: 'Minta SM perbaiki demo?', message: 'Minta Scrum Master memperbaiki demo yang bermasalah?', confirmLabel: 'Ya, minta perbaiki'})">Minta SM Perbaiki</button></form>
        @else
            <p>Jalankan project di server demo sementara (port unik per project).</p>
            <form method="POST" action="{{ route('projects.demo.start', $project) }}">@csrf<button class="button button-primary" type="submit">Jalankan demo</button></form>
        @endif
        <p style="margin-top:12px"><a class="button button-ghost" href="{{ route('projects.download', $project) }}">Download project (ZIP)</a></p>
    </section>

    <section class="backlog-launch empty-panel">
        <div class="panel-heading"><div><p class="section-kicker">SPRINT BACKLOG</p><h2>{{ $project->stories_count }} User Story</h2></div><span class="soft-badge">PO approval required</span></div>
        <p>Satu Sprint Backlog untuk project ini. Buat beberapa Sprint dan susun User Story di dalam Sprint masing-masing.</p>
        <a class="button button-primary" href="{{ route('projects.sprint-backlog.index', $project) }}">Buka Sprint Backlog <span aria-hidden="true">→</span></a>
    </section>
@endsection
