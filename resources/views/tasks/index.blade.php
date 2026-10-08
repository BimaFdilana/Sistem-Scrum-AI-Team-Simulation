@extends('layouts.workspace', ['title' => 'Tasks · '.$story->title, 'breadcrumb' => 'Projects / '.$story->project->name.' / Backlog / Tasks'])

@section('content')
    <section class="page-heading">
        <div>
            <p class="eyebrow"><span class="eyebrow-line"></span>TASK BREAKDOWN</p>
            <h1>{{ $story->title }}</h1>
            <p class="page-subtitle">Task diikat ke revision story {{ $story->revision }} dan acceptance criteria yang disetujui.</p>
        </div>
        <div class="heading-actions">
            <a class="button button-ghost" href="{{ route('projects.stories.index', $story->project) }}">Backlog</a>
            @if ($scopeApproved)
                <a class="button button-primary" href="{{ route('stories.tasks.create', $story) }}">＋ Tambah task</a>
            @endif
        </div>
    </section>

    @if (session('status'))<div class="success-banner" role="status">{{ session('status') }}</div>@endif
    @error('scope')<div class="form-alert" role="alert">{{ $message }}</div>@enderror

    @if (! $scopeApproved)
        <section class="notice-bar"><span class="notice-icon">i</span><p><strong>Story scope belum approved untuk revision saat ini.</strong> Task baru tidak dapat dibuat sampai Product Owner menyetujui scope.</p></section>
    @endif

    <section class="task-list" aria-label="Task breakdown">
        @forelse ($tasks as $task)
            @php($staleStoryRevision = $task->story_revision !== $story->revision)
            <article class="task-card">
                <div class="task-card-mark">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</div>
                <div class="task-card-main">
                    <div class="task-title-row"><h2>{{ $task->title }}</h2><span class="task-status">{{ $task->status }}</span></div>
                    @if ($task->description)<p>{{ $task->description }}</p>@endif
                    <div class="task-meta"><span>Assignee <code>{{ $task->assignee_profile }}</code></span><span>Story rev {{ $task->story_revision }}</span></div>
                    @if ($staleStoryRevision)<div class="stale-warning">STALE: task perlu direview ulang karena story telah berubah.</div>@endif
                </div>
            </article>
        @empty
            <section class="project-empty">
                <span class="empty-icon">⌁</span>
                <p class="section-kicker">NO TASKS</p>
                <h2>Belum ada task breakdown</h2>
                <p>Uraikan story menjadi task kecil yang dapat ditugaskan ke Backend atau Frontend Developer. Pembuatan task belum menjalankan agent.</p>
                @if ($scopeApproved)<a class="button button-primary" href="{{ route('stories.tasks.create', $story) }}">Tambah task pertama</a>@endif
            </section>
        @endforelse
    </section>

    <section class="notice-bar task-execution-gate"><span class="notice-icon">i</span><p><strong>Task execution masih terkunci.</strong> Agent belum dijalankan sampai isolasi filesystem dan Git worktree per task diverifikasi.</p></section>
@endsection
