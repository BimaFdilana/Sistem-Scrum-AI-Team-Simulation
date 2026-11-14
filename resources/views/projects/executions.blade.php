@extends('layouts.workspace', ['title' => 'Eksekusi · '.$project->name, 'breadcrumb' => $project->name.' / Eksekusi'])

@section('content')
    <section class="page-heading">
        <div>
            <p class="eyebrow"><span class="eyebrow-line"></span>EKSEKUSI</p>
            <h1>Eksekusi {{ $project->name }}</h1>
            <p class="page-subtitle">Semua pengerjaan story backlog dan perintah chat dalam satu tempat. Mulai dari yang <strong>perlu keputusan Anda</strong>.</p>
        </div>
    </section>

    @if (session('status'))<div class="success-banner" role="status">{{ session('status') }}</div>@endif

    <nav class="ex-filters" aria-label="Saring eksekusi">
        <a class="ex-filter {{ $filter === 'all' ? 'is-active' : '' }}" href="{{ route('projects.executions.index', [$project, 'filter' => 'all']) }}">Semua <span>{{ $total }}</span></a>
        @foreach (\App\Support\Execution\ProjectRuns::GROUPS as $key => $label)
            <a class="ex-filter is-{{ $key }} {{ $filter === $key ? 'is-active' : '' }}" href="{{ route('projects.executions.index', [$project, 'filter' => $key]) }}">{{ $label }} <span>{{ $counts[$key] }}</span></a>
        @endforeach
    </nav>

    <section class="ex-list" aria-label="Daftar eksekusi">
        @forelse ($rows as $row)
            @php
                $run = $row['run'];
                $story = $run->story;
                $statusText = $row['group'] === 'done'
                    ? 'Diputuskan '.$story->acceptanceLabel()
                    : \App\Http\Controllers\ChatCommandController::label($run->status);
            @endphp
            <a class="ex-row is-{{ $row['group'] }}" href="{{ route('stories.executions.show', [$run->story_id, $run]) }}">
                <span class="ex-kind">{{ $story?->isChatCommand() ? 'Perintah chat' : 'Story' }}</span>
                <span class="ex-title">{{ \App\Support\Ui\FriendlyText::clean($run->subjectLabel()) }}<small>Eksekusi #{{ $run->id }} · {{ $run->updated_at?->translatedFormat('d M, H:i') }}</small></span>
                <span class="ex-status is-{{ $row['group'] }}">{{ $statusText }}</span>
                <span class="ex-open" aria-hidden="true">→</span>
            </a>
        @empty
            <div class="ex-empty">
                <h2>{{ $filter === 'decision' ? 'Tidak ada yang menunggu keputusan Anda' : 'Belum ada eksekusi di kelompok ini' }}</h2>
                <p>Eksekusi dimulai dari tombol <strong>Eksekusi User Story</strong> di Sprint Backlog, atau dari perintah di <a href="{{ route('chat.index', ['project_id' => $project->id, 'tab' => 'tanya']) }}">Chat Scrum Master</a>.</p>
            </div>
        @endforelse
    </section>
@endsection
