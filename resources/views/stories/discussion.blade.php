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
                                'PROVIDER_TIMEOUT' => 'Provider melewati batas waktu.',
                                'PROVIDER_CONNECTION' => 'Provider tidak dapat dihubungi.',
                                'INVALID_JSON_SCHEMA' => 'Jawaban agent tidak mengikuti format yang diminta.',
                                'SAFETY_REJECTION' => 'Jawaban ditolak oleh kebijakan keamanan.',
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
                        <p class="discussion-banner"><strong>Poin Fibonacci final: {{ $run->consensus_points }}</strong> · Laporan Scrum Master tersimpan.</p>
                    @elseif ($run->status === 'NO_CONSENSUS')
                        <p class="discussion-banner discussion-banner--danger"><strong>NO_CONSENSUS</strong> · Dua putaran selesai tanpa tiga persetujuan eksplisit yang sama.</p>
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
            <p class="notice-bar">Belum ada eksekusi atau revisi QA. Bagian ini tetap kosong sampai executor terisolasi dan bukti QA tersedia.</p>
        </section>
    @endif
@endsection
