@extends('layouts.workspace', ['title' => $sprint->name, 'breadcrumb' => 'Projects / '.$sprint->project->name.' / Sprint Backlog / '.$sprint->name])

@section('content')
    <section class="page-heading">
        <div><p class="eyebrow"><span class="eyebrow-line"></span>SPRINT BACKLOG · {{ $sprint->name }}</p><h1>{{ $sprint->name }}</h1><p class="page-subtitle">Dibuat {{ $sprint->created_at?->format('d M Y H:i') }} · {{ $sprint->stories->count() }} User Story</p></div>
        <a class="text-link" href="{{ route('projects.sprint-backlog.index', $sprint->project) }}">← Kembali ke Sprint Backlog</a>
    </section>
    @if (session('status'))<div class="success-banner" role="status">{{ session('status') }}</div>@endif
    @foreach (['database', 'story', 'scope', 'start', 'goal'] as $errorKey)@error($errorKey)<div class="form-alert" role="alert">{{ $message }}</div>@enderror @enderror

    <section class="sprint-summary empty-panel">
        <div class="panel-heading"><div><p class="section-kicker">SPRINT GOAL</p><h2>Tujuan Sprint</h2></div><span class="scope-pill {{ $sprint->status === 'ACTIVE' ? 'scope-approved' : 'scope-pending' }}">{{ $sprint->status }}</span></div>
        @if ($sprint->goal)<p>{{ $sprint->goal }}</p><p class="field-help">{{ $sprint->goal_source === 'AI_DRAFT' ? 'Draf Scrum Master AI · tinjau dan setujui sebagai PO.' : 'Goal lama · minta Scrum Master AI merumuskan ulang sebelum persetujuan.' }}</p>@else<p class="empty-inline">Belum dirumuskan. Tambahkan story dan minta Scrum Master AI membuat Sprint Goal.</p>@endif
        <div class="approval-rule"><span class="rule-check">{{ $sprint->scope_approved_revision === $sprint->revision ? '✓' : '·' }}</span><span>Scope revision {{ $sprint->revision }} {{ $sprint->scope_approved_revision === $sprint->revision ? 'disetujui PO' : 'belum disetujui PO' }}</span></div>
    </section>

    <section class="sprint-scope-section">
        <div class="section-heading"><div><p class="section-kicker">USER STORIES</p><h2>Stories dalam {{ $sprint->name }}</h2></div>@if ($sprint->status === 'PLANNING')<a class="button button-primary" href="{{ route('sprints.stories.create', $sprint) }}">＋ Tambah User Story</a>@endif</div>
        @forelse ($sprint->stories as $story)
            <article class="scope-option">
                <div class="scope-option-heading"><span class="scope-pill">{{ $story->display_id }}</span><strong>{{ $story->actor?->name ?: 'Aktor belum dipilih' }}</strong>@if ($sprint->status === 'PLANNING')<a class="button button-ghost" href="{{ route('stories.edit', $story) }}">Edit</a>@endif</div>
                <div class="story-planning-fields"><p><strong>Saya Ingin [Fitur]:</strong> {{ $story->title }}</p><p><strong>Agar [Tujuan]:</strong> {{ $story->sprint_purpose }}</p><div class="story-criteria"><strong>Acceptance Criteria</strong><pre>{{ $story->acceptance_criteria }}</pre></div><p><strong>Status:</strong> {{ str_replace('_', ' ', $story->sprint_story_status ?: 'NOT_STARTED') }} · <strong>Scope:</strong> {{ str_replace('_', ' ', $story->scope_status) }}</p></div>
            </article>
        @empty
            <p class="empty-inline">Belum ada User Story di Sprint ini.</p>
        @endforelse
    </section>

    @if ($sprintSchemaReady && $detailsReady && $sprint->status === 'PLANNING')
        <div class="sprint-gate-actions">
            @if ($sprint->stories->isNotEmpty())
                <form method="POST" action="{{ route('sprints.goal.generate', $sprint) }}">@csrf<button class="button button-ghost" type="submit">{{ $sprint->goal ? 'Buat ulang draf Sprint Goal dengan Scrum Master AI' : 'Buat Sprint Goal dengan Scrum Master AI' }}</button></form>
                @if ($sprint->goal && $sprint->goal_source === 'AI_DRAFT')
                    <form method="POST" action="{{ route('sprints.scope.approve', $sprint) }}">@csrf<button class="button button-ghost" type="submit">PO · Setujui Sprint Backlog</button></form>
                @endif
                @if ($sprint->scope_approved_revision === $sprint->revision)
                    <form method="POST" action="{{ route('sprints.start', $sprint) }}">@csrf<button class="button button-primary" type="submit">Mulai Sprint →</button></form>
                @endif
            @endif
        </div>
    @elseif (! $sprintSchemaReady)
        <section class="notice-bar"><span class="notice-icon">i</span><p><strong>Menunggu migrasi Sprint Backlog.</strong> Perubahan goal dan persetujuan sementara dinonaktifkan. Data yang sudah ada tetap tersimpan.</p></section>
    @endif
@endsection
