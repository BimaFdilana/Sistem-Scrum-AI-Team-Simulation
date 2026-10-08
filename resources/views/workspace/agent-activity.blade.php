@extends('layouts.workspace', ['title' => 'Log activity · '.$agent['name'], 'breadcrumb' => 'Agent / Log activity'])

@section('content')
    <section class="page-heading">
        <div>
            <p class="eyebrow"><span class="eyebrow-line"></span>ACTIVITY LOG</p>
            <h1>{{ $agent['name'] }}</h1>
            <p class="page-subtitle">Daftar task yang tercatat untuk profil ini, diurutkan dari yang terbaru.</p>
        </div>
        <a class="button button-secondary activity-back-link" href="{{ route('agents.index', [], false) }}">← Kembali ke Agent</a>
    </section>

    <section class="activity-panel">
        <p class="activity-note">Log ini menunjukkan task yang ditugaskan dan dicatat. Catatan task tidak berarti task telah dieksekusi oleh agent.</p>
        @if ($tasks->isEmpty())
            <div class="activity-empty">
                <h2>Belum ada aktivitas task yang tercatat</h2>
                <p>Log akan muncul setelah ada task yang ditugaskan ke {{ $agent['name'] }}.</p>
            </div>
        @else
            <div class="activity-table-wrap">
                <table class="activity-table">
                    <thead>
                        <tr>
                            <th scope="col">Isi log (task)</th>
                            <th scope="col">Tanggal</th>
                            <th scope="col">Jam ({{ config('app.timezone') }})</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tasks as $task)
                            <tr>
                                <td>
                                    <strong>{{ $task->title }}</strong>
                                    @if ($task->description)
                                        <p>{{ $task->description }}</p>
                                    @endif
                                    <small>Status task saat dicatat: {{ $task->status }}</small>
                                </td>
                                <td>{{ $task->created_at->format('d/m/Y') }}</td>
                                <td>{{ $task->created_at->format('H:i') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
    <section class="activity-panel">
        <p class="section-kicker">REFINEMENT FIBONACCI</p>
        <h2>Aktivitas estimasi User Story</h2>
        <p class="activity-note">Log ini mencatat permintaan estimasi yang benar-benar dicoba ke profil agent; status membedakan hasil berhasil dan gagal.</p>
        @if ($estimationRuns->isEmpty())
            <div class="activity-empty">
                <h3>Belum ada aktivitas estimasi</h3>
                <p>Aktivitas muncul setelah Scrum Master meminta profil ini mengestimasi User Story.</p>
            </div>
        @else
            <div class="activity-table-wrap">
                <table class="activity-table">
                    <thead><tr><th scope="col">User Story</th><th scope="col">Putaran / hasil</th><th scope="col">Rationale</th><th scope="col">Waktu</th></tr></thead>
                    <tbody>
                        @foreach ($estimationRuns as $agentRun)
                            @php($story = $agentRun->estimationRun->story)
                            <tr>
                                <td><strong>{{ $story->display_id }} · {{ $story->title }}</strong><small>Revisi {{ $agentRun->estimationRun->story_revision }} · Run #{{ $agentRun->story_estimation_run_id }}</small></td>
                                <td>Putaran {{ $agentRun->round }} · {{ $agentRun->status }}@if ($agentRun->estimate_points)<small>{{ $agentRun->estimate_points }} poin Fibonacci</small>@elseif ($agentRun->error_code)<small>{{ $agentRun->error_code }}</small>@endif</td>
                                <td>{{ $agentRun->rationale ?: 'Belum ada hasil yang dapat disimpan.' }}</td>
                                <td>{{ ($agentRun->completed_at ?: $agentRun->started_at)?->format('d/m/Y H:i') ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
