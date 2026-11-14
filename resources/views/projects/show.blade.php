@extends('layouts.workspace', ['title' => $project->name, 'breadcrumb' => 'Projects / '.$project->name])

@section('content')
    <section class="bk-head">
        <div class="bk-head-main">
            <a class="text-link" href="{{ route('projects.index') }}">← Semua projects</a>
            <h1>{{ $project->name }}</h1>
            @if ($project->description)<p class="page-subtitle">{{ $project->description }}</p>@endif
            <div class="bk-chips">
                <span class="bk-chip">{{ $project->project_type === 'NEW_PROJECT' ? 'Project baru' : 'Project existing' }}@if ($project->stack) · {{ $project->stack }}@endif</span>
                <span class="bk-chip"><i class="status-dot {{ $project->status === 'BASELINE_READY' ? 'status-ready' : 'status-idle' }}"></i>{{ str_replace('_', ' ', $project->status) }}</span>
                @if ($demo && $demoUrl)
                    <a class="bk-chip is-ok" href="{{ $demoUrl }}" target="_blank" rel="noopener">Demo aktif ↗</a>
                @else
                    <span class="bk-chip">Demo mati</span>
                @endif
            </div>
        </div>
        @if ($sprintSchemaReady)
            <div class="heading-actions"><button class="button button-primary" type="button" data-sprint-dialog-open>＋ Buat Sprint</button></div>
        @endif
    </section>

    @if (session('status'))<div class="success-banner" role="status">{{ session('status') }}</div>@endif
    @foreach (['database', 'story', 'goal', 'scope', 'start', 'estimation', 'acceptance'] as $errorKey)
        @error($errorKey)<div class="form-alert" role="alert">{{ $message }}</div>@enderror
    @endforeach
    @if (! $estimationSchemaReady)
        <div class="notice-bar"><span class="notice-icon">i</span><p>Estimasi Fibonacci multi-agent menunggu migrasi database estimasi.</p></div>
    @endif

    <dl class="bk-stats" aria-label="Ringkasan project">
        <div><dt>Sprint</dt><dd>{{ $stats['sprints'] }}</dd></div>
        <div><dt>User story</dt><dd>{{ $stats['stories'] }}</dd></div>
        <div><dt>Diterima PO</dt><dd>{{ $stats['accepted'] }}<small>/ {{ $stats['stories'] }}</small></dd></div>
        <div><dt>Total poin</dt><dd>{{ $stats['points'] }}</dd></div>
    </dl>

    @include('projects.partials.board')

    @include('projects.partials.dialogs')


@endsection
