@extends('layouts.workspace', ['title' => 'Sprints · '.$project->name, 'breadcrumb' => 'Projects / '.$project->name.' / Sprints'])

@section('content')
    <section class="page-heading">
        <div>
            <p class="eyebrow"><span class="eyebrow-line"></span>SPRINT PLANNING</p>
            <h1>{{ $project->name }} sprints</h1>
            <p class="page-subtitle">Satu Product Backlog per project; setiap Sprint dan user story berada di dalam backlog tersebut.</p>
        </div>
        <div class="heading-actions">
            <a class="button button-ghost" href="{{ route('projects.show', $project) }}">Ringkasan project</a>
            <a class="button button-primary" href="{{ route('projects.sprints.create', $project) }}">＋ Buat sprint</a>
        </div>
    </section>

    @if (session('status'))<div class="success-banner" role="status">{{ session('status') }}</div>@endif

    @if ($sprints->isEmpty())
        <section class="project-empty">
            <span class="empty-icon">◷</span>
            <p class="section-kicker">NO SPRINTS</p>
            <h2>Belum ada sprint plan</h2>
            <p>Tentukan tujuan dan jadwal Sprint. Setelah dibuat, tambahkan user story dari halaman Sprint.</p>
            <a class="button button-primary" href="{{ route('projects.sprints.create', $project) }}">Buat sprint pertama</a>
        </section>
    @else
        <section class="sprint-list" aria-label="Daftar sprint">
            @foreach ($sprints as $sprint)
                <a class="sprint-row" href="{{ route('sprints.show', $sprint) }}">
                    <span class="sprint-index">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                    <span class="sprint-main"><strong>{{ $sprint->name }}</strong><small>Fitur / Saya ingin: {{ $sprint->goal }}</small></span>
                    <span class="sprint-dates">{{ $sprint->start_date->format('d M') }} — {{ $sprint->end_date->format('d M Y') }}</span>
                    <span class="project-status"><i class="{{ $sprint->status === 'ACTIVE' ? 'status-ready' : '' }}"></i>{{ $sprint->status }}</span>
                    <span class="sprint-count">{{ $sprint->stories_count }} stories</span>
                    <span aria-hidden="true">→</span>
                </a>
            @endforeach
        </section>
    @endif
@endsection
