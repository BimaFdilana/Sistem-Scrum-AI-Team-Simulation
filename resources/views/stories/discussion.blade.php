@extends('layouts.workspace', ['title' => 'Diskusi Tim · '.$story->display_id, 'breadcrumb' => 'Projects / '.$story->project->name.' / Diskusi Tim'])

@section('content')
    @php
        $finalRun = $runs->first(fn ($r) => $r->status === 'COMPLETED' && $r->consensus_points && (int) $r->story_revision === (int) $story->revision);
        $statusLabel = fn (?string $status) => match ($status) {
            'COMPLETED' => ['selesai', 'is-ok'],
            'RUNNING', 'QUEUED', 'REPORT_PENDING' => ['berjalan', 'is-run'],
            'FAILED', 'PARTIAL', 'NO_CONSENSUS' => ['perlu tindakan', 'is-warn'],
            default => [strtolower((string) $status), ''],
        };
    @endphp

    <section class="dc-head">
        <div class="dc-head-main">
            <a class="text-link" href="{{ route('projects.show', $story->project_id) }}">← Kembali ke project</a>
            @if ($story->sprint_id) · <a class="text-link" href="{{ route('chat.index', ['sprint' => $story->sprint_id]) }}">Laporan di chat Scrum Master</a>@endif
            <h1><span class="dc-id">{{ $story->display_id }}</span> Diskusi Tim</h1>
            <p class="page-subtitle">{{ $story->title }}</p>
            <div class="dc-chips">
                <span class="dc-chip">Revisi story {{ $story->revision }}</span>
                @if ($story->sprint)<span class="dc-chip">{{ $story->sprint->name }}</span>@endif
                @if ($currentRun)
                    @php([$label, $class] = $statusLabel($currentRun->status))
                    <span class="dc-chip {{ $class }}">Diskusi terakhir: {{ $label }}</span>
                @endif
                @if ($finalRun)
                    <span class="dc-chip is-ok">Poin final {{ $finalRun->consensus_points }} ({{ \App\Services\StoryEstimationService::category($finalRun->consensus_points) }})</span>
                @endif
            </div>
        </div>
    </section>

    @if (session('status'))<div class="success-banner" role="status">{{ session('status') }}</div>@endif
    @error('estimation')<div class="form-alert" role="alert">{{ $message }}</div>@enderror

    @if (! $schemaReady)
        <p class="notice-bar" role="status">Diskusi tim menunggu migrasi database estimasi. Tidak ada pesan yang dibuat-buat.</p>
    @else
        <div class="dc-layout">
            <main class="dc-main" aria-label="Percakapan tim">
                @forelse ($runs as $run)
                    @if ($loop->first)
                        @include('stories.partials.discussion-run', ['run' => $run, 'story' => $story])
                    @else
                        @if ($loop->index === 1)
                            <h2 class="dc-older-title">Diskusi sebelumnya</h2>
                        @endif
                        <details class="dc-older">
                            <summary>
                                <span>Run #{{ $run->id }} · revisi {{ $run->story_revision }} · {{ $run->created_at?->format('d M Y H:i') }}</span>
                                @php([$label, $class] = $statusLabel($run->status))
                                <span class="dc-chip {{ $class }}">{{ $label }}@if ($run->consensus_points) · {{ $run->consensus_points }} poin @endif</span>
                            </summary>
                            @include('stories.partials.discussion-run', ['run' => $run, 'story' => $story])
                        </details>
                    @endif
                @empty
                    <div class="dc-empty">
                        <span class="dc-empty-mark">✦</span>
                        <h2>Belum ada diskusi</h2>
                        <p>Mulai Diskusi Fibonacci dari halaman project. Percakapan Backend, Frontend, dan QA muncul di sini.</p>
                        <a class="button button-primary" href="{{ route('projects.show', $story->project_id) }}">Buka project</a>
                    </div>
                @endforelse
            </main>

            <aside class="dc-side" aria-label="Rincian story dan eksekusi">
                <details class="dc-card dc-story" open data-collapse-on-small>
                    <summary>User story</summary>
                    <div class="dc-tabs" role="tablist" aria-label="Versi user story">
                        <button type="button" role="tab" class="dc-tab is-active" data-story-tab="original" aria-selected="true">Data asli</button>
                        <button type="button" role="tab" class="dc-tab" data-story-tab="refined" aria-selected="false">Hasil diskusi SM</button>
                    </div>
                    <p class="dc-hint">Eksekusi memakai versi <strong>{{ $story->hasRefinement() ? 'Hasil Diskusi SM' : 'Data Asli' }}</strong>.</p>

                    <dl class="dc-fields" data-story-panel="original" role="tabpanel">
                        <div><dt>Saya ingin (fitur)</dt><dd>{{ $story->title }}</dd></div>
                        <div><dt>Agar (tujuan)</dt><dd>{{ $story->sprint_purpose ?? '—' }}</dd></div>
                        <div><dt>Acceptance criteria</dt><dd>{{ $story->acceptance_criteria ?? '—' }}</dd></div>
                        <div><dt>Aktor</dt><dd>{{ $story->actor?->name ?? '—' }}</dd></div>
                    </dl>
                    <div data-story-panel="refined" role="tabpanel" hidden>
                        @if ($story->hasRefinement())
                            <p class="dc-hint">Disempurnakan Scrum Master dari Run #{{ $story->refined_from_run_id }} · {{ $story->refined_at?->format('d M Y H:i') }}</p>
                            <dl class="dc-fields">
                                <div><dt>Saya ingin (fitur)</dt><dd>{{ $story->refined_title }}</dd></div>
                                <div><dt>Agar (tujuan)</dt><dd>{{ $story->refined_purpose ?? '—' }}</dd></div>
                                <div><dt>Acceptance criteria</dt><dd>{{ $story->refined_acceptance_criteria ?? '—' }}</dd></div>
                            </dl>
                        @else
                            <p class="dc-hint">Belum ada hasil diskusi. Versi final Scrum Master muncul di sini setelah laporan kesepakatan tersimpan.</p>
                        @endif
                    </div>
                </details>

                <section class="dc-card" aria-labelledby="execution-heading">
                    <h2 id="execution-heading" class="dc-card-title">Eksekusi &amp; revisi QA</h2>
                    @forelse ($executions as $execution)
                        <a class="dc-exec" href="{{ route('stories.executions.show', [$story, $execution]) }}">
                            <span><strong>Eksekusi #{{ $execution->id }}</strong><small>{{ $execution->created_at->format('d M Y H:i') }} · {{ $execution->tasks_count }} task</small></span>
                            <span class="dc-chip">{{ str_replace('_', ' ', $execution->status) }}</span>
                            @if ($execution->summary)<em>{{ $execution->summary }}</em>@endif
                        </a>
                    @empty
                        <p class="dc-hint">Belum ada eksekusi. Mulai dari halaman project setelah estimasi disepakati.</p>
                    @endforelse
                </section>
            </aside>
        </div>

        <script>
            (function () {
                var buttons = document.querySelectorAll('[data-story-tab]');
                var panels = document.querySelectorAll('[data-story-panel]');
                buttons.forEach(function (button) {
                    button.addEventListener('click', function () {
                        buttons.forEach(function (b) { b.classList.remove('is-active'); b.setAttribute('aria-selected', 'false'); });
                        panels.forEach(function (p) { p.hidden = true; });
                        button.classList.add('is-active');
                        button.setAttribute('aria-selected', 'true');
                        var panel = document.querySelector('[data-story-panel="' + button.dataset.storyTab + '"]');
                        if (panel) panel.hidden = false;
                    });
                });
                // Layar kecil: kartu story dilipat agar percakapan langsung terlihat.
                if (window.matchMedia('(max-width: 900px)').matches) {
                    document.querySelectorAll('[data-collapse-on-small]').forEach(function (d) { d.removeAttribute('open'); });
                }
            })();
        </script>
    @endif
@endsection
