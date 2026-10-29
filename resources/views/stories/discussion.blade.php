@extends('layouts.workspace', ['title' => 'Diskusi Tim · '.$story->display_id, 'breadcrumb' => 'Projects / '.$story->project->name.' / Diskusi Tim'])

@section('content')
    <section class="page-heading chat-heading">
        <div>
            <p class="eyebrow"><span class="eyebrow-line"></span>TEAM CHAT · BACA SAJA</p>
            <h1>{{ $story->display_id }} — Diskusi Tim</h1>
            <p class="page-subtitle">{{ $story->title }} · Revisi story {{ $story->revision }}</p>
        </div>
        <a class="button button-ghost" href="{{ route('projects.sprint-backlog.index', $story->project_id) }}">← Kembali ke Sprint Backlog</a>
    </section>

    @if (! $schemaReady)
        <p class="notice-bar" role="status">Diskusi tim menunggu migrasi database estimasi. Tidak ada pesan yang dibuat-buat.</p>
    @else
        <section class="story-version" aria-labelledby="story-version-heading">
            <style>
                .story-tab-buttons { display: flex; gap: .5rem; }
                .story-tab-button { padding: .45rem 1rem; border-radius: 999px; border: 1px solid var(--border, #d7dbe3); background: transparent; cursor: pointer; font: inherit; }
                .story-tab-button.is-active { background: var(--accent, #1a73e8); color: #fff; border-color: transparent; }
                .story-field { margin: .9rem 0; }
                .story-field > dt { font-size: .75rem; text-transform: uppercase; letter-spacing: .06em; opacity: .65; margin-bottom: .25rem; }
                .story-field > dd { margin: 0; white-space: pre-line; }
            </style>
            <div class="team-discussion-toolbar">
                <div>
                    <p class="section-kicker">USER STORY</p>
                    <h2 id="story-version-heading">Versi User Story</h2>
                    <p class="section-description">Bandingkan data asli dengan hasil akhir diskusi dari Scrum Master. Eksekusi memakai versi <strong>{{ $story->hasRefinement() ? 'Hasil Diskusi SM' : 'Data Asli' }}</strong>.</p>
                </div>
                <div class="story-tab-buttons" role="tablist" aria-label="Versi user story">
                    <button type="button" role="tab" class="story-tab-button is-active" data-story-tab="original" aria-selected="true">Data Asli</button>
                    <button type="button" role="tab" class="story-tab-button" data-story-tab="refined" aria-selected="false">Hasil Diskusi SM</button>
                </div>
            </div>
            <div data-story-panel="original" role="tabpanel">
                <dl>
                    <div class="story-field"><dt>Saya Ingin (Fitur)</dt><dd>{{ $story->title }}</dd></div>
                    <div class="story-field"><dt>Agar (Tujuan)</dt><dd>{{ $story->sprint_purpose ?? '—' }}</dd></div>
                    <div class="story-field"><dt>Acceptance Criteria</dt><dd>{{ $story->acceptance_criteria ?? '—' }}</dd></div>
                    <div class="story-field"><dt>Aktor</dt><dd>{{ $story->actor?->name ?? '—' }}</dd></div>
                </dl>
            </div>
            <div data-story-panel="refined" role="tabpanel" hidden>
                @if ($story->hasRefinement())
                    <p class="notice-bar">Disempurnakan Scrum Master dari Run #{{ $story->refined_from_run_id }} · {{ $story->refined_at?->format('d M Y H:i') }}</p>
                    <dl>
                        <div class="story-field"><dt>Saya Ingin (Fitur)</dt><dd>{{ $story->refined_title }}</dd></div>
                        <div class="story-field"><dt>Agar (Tujuan)</dt><dd>{{ $story->refined_purpose ?? '—' }}</dd></div>
                        <div class="story-field"><dt>Acceptance Criteria</dt><dd>{{ $story->refined_acceptance_criteria ?? '—' }}</dd></div>
                    </dl>
                @else
                    <p class="notice-bar">Belum ada hasil diskusi. Versi final dari Scrum Master akan muncul di sini setelah laporan kesepakatan tersimpan.</p>
                @endif
            </div>
            <script>
                (function () {
                    const buttons = document.querySelectorAll('[data-story-tab]');
                    const panels = document.querySelectorAll('[data-story-panel]');
                    buttons.forEach(function (button) {
                        button.addEventListener('click', function () {
                            buttons.forEach(function (b) { b.classList.remove('is-active'); b.setAttribute('aria-selected', 'false'); });
                            panels.forEach(function (p) { p.hidden = true; });
                            button.classList.add('is-active');
                            button.setAttribute('aria-selected', 'true');
                            const panel = document.querySelector('[data-story-panel="' + button.dataset.storyTab + '"]');
                            if (panel) panel.hidden = false;
                        });
                    });
                })();
            </script>
        </section>

        <section class="team-discussion-shell" aria-labelledby="fibonacci-heading">
            <div class="team-discussion-toolbar">
                <div>
                    <p class="section-kicker">FIBONACCI TEAM CHAT</p>
                    <h2 id="fibonacci-heading">Diskusi Fibonacci</h2>
                    <p class="section-description">Percakapan nyata Backend, Frontend, dan QA. Product Owner hanya dapat membaca.</p>
                </div>
                @if ($currentRun)
                    <span class="chat-connection {{ $currentRun->status === 'COMPLETED' ? 'is-connected' : '' }}">{{ $currentRun->status }} · Maks. 2 putaran</span>
                @endif
            </div>

            @forelse ($runs as $run)
                <article class="team-run" aria-label="Run {{ $run->id }}">
                    <div class="team-run-header">
                        <div><span class="chat-message-label">RUN #{{ $run->id }}</span><strong>Revisi {{ $run->story_revision }}</strong></div>
                        <span class="scope-pill">{{ $run->status }}</span>
                    </div>
                    @if ($run->story_revision !== $story->revision)
                        <p class="estimation-stale">Run kedaluwarsa karena User Story telah berubah.</p>
                    @endif

                    @php
                        $failedAgent = $run->agentRuns->where('status', 'FAILED')->sortByDesc('id')->first();
                    @endphp
                    @if ($failedAgent)
                        @php
                            $failureText = match ($failedAgent->error_code) {
                                'PROVIDER_OVERLOADED' => 'Provider sedang sibuk.',
                                'PROVIDER_RATE_LIMITED' => 'Provider sedang dibatasi (rate limit, coba lagi nanti).',
                                'PROVIDER_TIMEOUT' => 'Provider melewati batas waktu.',
                                'PROVIDER_CONNECTION' => 'Provider tidak dapat dihubungi.',
                                'INVALID_JSON_SCHEMA' => 'Jawaban agent tidak mengikuti format yang diminta.',
                                'SAFETY_REJECTION' => 'Jawaban ditolak oleh kebijakan keamanan.',
                                'LENGTH_REJECTION' => 'Jawaban agent melebihi batas panjang yang diizinkan.',
                                'EMPTY_RESPONSE' => 'Jawaban agent kosong.',
                                'STALE_OR_CANCELLED' => 'Run menjadi kedaluwarsa atau dibatalkan.',
                                'WORKER_FAILED' => 'Worker diskusi berhenti sebelum proses selesai.',
                                'WORKER_TIMEOUT' => 'Worker diskusi melewati batas waktu.',
                                'WORKER_STALE' => 'Worker diskusi tidak mengirim heartbeat.',
                                default => 'Permintaan agent gagal.',
                            };
                        @endphp
                        <p class="discussion-banner discussion-banner--danger"><strong>{{ strtoupper($failedAgent->profile_slug) }} · Putaran {{ $failedAgent->round }}:</strong> {{ $failureText }}</p>
                        @if ($run->status === 'FAILED' && (int) $run->requested_by === (int) auth()->id() && (int) $run->story_revision === (int) $story->revision)
                            <form method="POST" action="{{ route('stories.estimations.agents.retry', [$story, $run, $failedAgent]) }}">
                                @csrf
                                <button class="button button-ghost" type="submit">Retry {{ strtoupper($failedAgent->profile_slug) }} saja</button>
                            </form>
                        @endif
                    @endif

                    @if ($run->status === 'COMPLETED' && $run->consensus_points)
                        <p class="discussion-banner"><strong>Poin Fibonacci final: {{ $run->consensus_points }}</strong> · {{ $run->consensus_method === 'deadlock_max' ? 'Deadlock — Scrum Master menerapkan nilai maksimum.' : 'Mufakat bulat tim.' }} Laporan Scrum Master tersimpan.</p>
                    @elseif ($run->status === 'NO_CONSENSUS')
                        <p class="discussion-banner discussion-banner--danger"><strong>NO_CONSENSUS</strong> · Bukti final tidak lengkap; run lama ini tidak menghasilkan poin.</p>
                    @elseif (in_array($run->status, ['FAILED', 'PARTIAL'], true))
                        <p class="discussion-banner discussion-banner--danger">Diskusi berhenti sebelum selesai. Gunakan Retry untuk membuat run baru.</p>
                    @else
                        <p class="discussion-banner">Diskusi sedang menunggu atau belum memiliki poin final.</p>
                    @endif

                    @if (in_array($run->status, ['FAILED', 'PARTIAL', 'NO_CONSENSUS'], true) && (int) $run->requested_by === (int) auth()->id() && (int) $run->story_revision === (int) $story->revision)
                        <form method="POST" action="{{ route('stories.estimations.retry', [$story, $run]) }}">@csrf<button class="button button-ghost" type="submit">Retry diskusi (run baru)</button></form>
                    @endif

                    <div class="chat-panel team-chat-panel" aria-label="Pesan Run {{ $run->id }}">
                        <div class="chat-transcript">
                            @forelse ($run->messages as $message)
                                @php
                                    $payload = json_decode($message->content, true);
                                    $roleClass = match ($message->sender_slug) {
                                        'backend-developer' => 'team-message--backend',
                                        'frontend-developer' => 'team-message--frontend',
                                        'qa-engineer' => 'team-message--qa',
                                        default => '',
                                    };
                                    $roleName = match ($message->sender_slug) {
                                        'backend-developer' => 'Backend Developer',
                                        'frontend-developer' => 'Frontend Developer',
                                        'qa-engineer' => 'QA Engineer',
                                        default => strtoupper($message->sender_slug),
                                    };
                                    $content = is_array($payload)
                                        ? ($payload['response'] ?? $payload['rationale'] ?? '')
                                        : 'Pesan terstruktur agent tidak dapat ditampilkan.';
                                @endphp
                                <article class="chat-message chat-message-assistant {{ $roleClass }}">
                                    <span class="chat-message-label">{{ $roleName }}</span>
                                    <div class="team-message-meta"><span>Putaran {{ $message->round }}</span>@if ($message->estimate_points)<span>{{ $message->estimate_points }} poin</span>@endif @if ($message->explicit_agree === true)<span class="agreement-badge agreement-badge--yes">Setuju eksplisit</span>@elseif ($message->explicit_agree === false)<span class="agreement-badge agreement-badge--no">Belum setuju</span>@endif <time datetime="{{ $message->created_at?->toIso8601String() }}">{{ $message->created_at?->format('d M Y H:i') }}</time></div>
                                    <p>{{ \Illuminate\Support\Str::limit($content, 500) }}</p>
                                </article>
                            @empty
                                <div class="chat-empty"><span class="chat-empty-mark">✦</span><h2>Belum ada pesan</h2><p>Pesan akan muncul setelah worker memproses agent.</p></div>
                            @endforelse
                        </div>
                        <div class="chat-composer team-readonly-composer"><span>Diskusi Tim · baca saja</span><span>PO tidak dapat mengirim pesan ke agent</span></div>
                    </div>
                    @if ($run->summary)<p class="sm-summary"><strong>Ringkasan Scrum Master:</strong> {{ \Illuminate\Support\Str::limit($run->summary, 600) }}</p>@endif
                </article>
            @empty
                <div class="chat-empty"><span class="chat-empty-mark">✦</span><h2>Belum ada diskusi</h2><p>Mulai Diskusi Fibonacci dari Sprint Backlog.</p></div>
            @endforelse
        </section>

        <section aria-labelledby="execution-heading" class="team-execution-empty">
            <h2 id="execution-heading">Eksekusi &amp; Revisi QA</h2>
            @forelse ($executions as $execution)
                <div class="task-row">
                    <div>
                        <strong>Eksekusi #{{ $execution->id }}</strong>
                        <small>{{ str_replace('_', ' ', $execution->status) }} · {{ $execution->tasks_count }} task · {{ $execution->created_at->format('d M Y H:i') }}</small>
                        @if ($execution->summary)<p>{{ \Illuminate\Support\Str::limit($execution->summary, 200) }}</p>@endif
                    </div>
                    <a class="button button-ghost" href="{{ route('stories.executions.show', [$story, $execution]) }}">Lihat</a>
                </div>
            @empty
                <p class="notice-bar">Belum ada eksekusi. Mulai dari Sprint Backlog setelah estimasi disepakati.</p>
            @endforelse
        </section>
    @endif
@endsection
