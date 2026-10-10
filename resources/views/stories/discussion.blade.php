@extends('layouts.workspace', ['title' => 'Diskusi Tim · '.$story->display_id, 'breadcrumb' => 'Projects / '.$story->project->name.' / Diskusi Tim'])

@section('content')
<style>
    .discussion-run { margin: 1rem 0 1.5rem; background:var(--panel); }
    .discussion-status { display:flex; justify-content:space-between; gap:1rem; align-items:center; flex-wrap:wrap; }
    .discussion-banner { border:1px solid rgba(124,226,188,.22); border-radius:var(--radius); padding:.75rem 1rem; margin:.75rem 0; background:rgba(124,226,188,.08); color:var(--text); }
    .discussion-banner--danger { border-color:rgba(243,140,145,.24); background:rgba(243,140,145,.09); color:var(--danger); }
    .chat-list { list-style:none; padding:1rem; margin:1rem 0; display:flex; flex-direction:column; gap:.75rem; border:1px solid var(--line); border-radius:var(--radius); background:var(--bg); }
    .chat-bubble { width:fit-content; max-width:min(760px,92%); border:1px solid var(--line-strong); border-left:3px solid var(--accent); border-radius:var(--radius); padding:.75rem .95rem; background:var(--panel-raised); color:var(--text); }
    .chat-bubble--backend { border-left-color:#7db5f3; background:linear-gradient(135deg,rgba(125,181,243,.10),var(--panel-raised) 85%); }
    .chat-bubble--frontend { align-self:flex-end; border-left-color:#b59af5; background:linear-gradient(135deg,rgba(181,154,245,.10),var(--panel-raised) 85%); }
    .chat-bubble--qa { border-left-color:var(--accent); background:linear-gradient(135deg,rgba(124,226,188,.10),var(--panel-raised) 85%); }
    .chat-meta { display:flex; gap:.55rem; align-items:center; flex-wrap:wrap; font-size:.82rem; color:var(--muted); }
    .chat-role { font-weight:700; color:var(--text); }
    .chat-bubble--backend .chat-role { color:#a9cefa; }
    .chat-bubble--frontend .chat-role { color:#cbb8fb; }
    .chat-bubble--qa .chat-role { color:var(--accent-strong); }
    .round-badge,.agreement-badge { border:1px solid var(--line); border-radius:999px; padding:.12rem .5rem; background:var(--panel-hover); color:var(--muted); }
    .agreement-badge--yes { border-color:rgba(124,226,188,.28); background:rgba(124,226,188,.11); color:var(--accent-strong); }
    .agreement-badge--no { border-color:rgba(243,140,145,.28); background:rgba(243,140,145,.11); color:var(--danger); }
    .chat-content { margin:.5rem 0 0; color:var(--text); white-space:pre-wrap; overflow-wrap:anywhere; }
    .sm-summary { border:1px solid rgba(232,196,125,.2); border-left:3px solid var(--amber); border-radius:var(--radius); padding:.65rem .85rem; background:rgba(232,196,125,.07); color:var(--text); }
    @media(max-width:640px) { .chat-list{padding:.7rem} .chat-bubble{max-width:100%} }
</style>
<section class="page-heading">
    <div>
        <p class="eyebrow">DISKUSI TIM · BACA SAJA</p>
        <h1>{{ $story->display_id }} — Lihat Diskusi Tim</h1>
        <p class="page-subtitle">{{ $story->title }} · Revisi story {{ $story->revision }}</p>
    </div>
    <a class="button button-ghost" href="{{ route('projects.sprint-backlog.index', $story->project_id) }}">Kembali ke Sprint Backlog</a>
</section>
@if (! $schemaReady)
    <p class="notice-bar" role="status">Diskusi tim menunggu migrasi database estimasi. Tidak ada pesan yang dibuat-buat.</p>
@else
    <section aria-labelledby="fibonacci-heading">
        <div class="discussion-status">
            <div><h2 id="fibonacci-heading">Diskusi Fibonacci</h2><p>Ringkasan diskusi nyata FE, BE, dan QA. PO hanya dapat membaca.</p></div>
            @if ($currentRun)
                <span class="scope-pill">STATUS {{ $currentRun->status }} · PUTARAN {{ min(3, (int) $currentRun->messages->max('round')) }}/3</span>
            @endif
        </div>
        @forelse ($runs as $run)
            <article class="story-card discussion-run">
                <div class="discussion-status"><h3>Run #{{ $run->id }} · Revisi {{ $run->story_revision }}</h3><span class="scope-pill">{{ $run->status }}</span></div>
                @if ($run->story_revision !== $story->revision)
                    <p class="estimation-stale">Kedaluwarsa: story telah berubah.</p>
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
                            default => 'Permintaan agent gagal.',
                        };
                    @endphp
                    <p class="discussion-banner discussion-banner--danger"><strong>{{ strtoupper($failedAgent->profile_slug) }} · Putaran {{ $failedAgent->round }}:</strong> {{ $failureText }} Tindakan berikutnya: periksa konfigurasi/koneksi agent lalu gunakan Retry jika status run mengizinkan.</p>
                @endif
                @if ($run->status === 'COMPLETED' && $run->consensus_points)
                    <p class="discussion-banner"><strong>Poin Fibonacci final: {{ $run->consensus_points }}</strong> · Laporan Scrum Master tersimpan.</p>
                @elseif ($run->status === 'NO_CONSENSUS')
                    <p class="discussion-banner discussion-banner--danger"><strong>NO_CONSENSUS</strong> · Tiga putaran selesai tanpa tiga persetujuan eksplisit yang sama. Tidak ada poin final.</p>
                @elseif (in_array($run->status, ['FAILED', 'PARTIAL'], true))
                    <p class="discussion-banner discussion-banner--danger">Diskusi berhenti sebelum selesai. Gunakan Retry untuk membuat run baru tanpa mengubah riwayat ini.</p>
                @else
                    <p class="discussion-banner">Belum ada poin final yang dapat dieksekusi.</p>
                @endif
                @if (in_array($run->status, ['FAILED', 'PARTIAL', 'NO_CONSENSUS'], true) && (int) $run->requested_by === (int) auth()->id() && (int) $run->story_revision === (int) $story->revision)
                    <form method="POST" action="{{ route('stories.estimations.retry', [$story, $run]) }}">@csrf<button class="button button-ghost" type="submit">Retry diskusi (run baru)</button></form>
                @endif
                <ol class="chat-list" aria-label="Pesan run {{ $run->id }}">
                    @forelse ($run->messages as $message)
                        @php
                            $payload = json_decode($message->content, true);
                            $roleClass = match ($message->sender_slug) { 'backend-developer' => 'chat-bubble--backend', 'frontend-developer' => 'chat-bubble--frontend', 'qa-engineer' => 'chat-bubble--qa', default => '' };
                            $roleName = match ($message->sender_slug) { 'backend-developer' => 'BE', 'frontend-developer' => 'FE', 'qa-engineer' => 'QA', default => strtoupper($message->sender_slug) };
                            $content = is_array($payload)
                                ? ($payload['response'] ?? $payload['rationale'] ?? '')
                                : 'Pesan terstruktur agent tidak dapat ditampilkan.';
                        @endphp
                        <li class="chat-bubble {{ $roleClass }}">
                            <div class="chat-meta"><span class="chat-role">{{ $roleName }}</span><span class="round-badge">Putaran {{ $message->round }}</span>@if ($message->estimate_points)<span>{{ $message->estimate_points }} poin</span>@endif @if ($message->explicit_agree === true)<span class="agreement-badge agreement-badge--yes">Setuju eksplisit</span>@elseif ($message->explicit_agree === false)<span class="agreement-badge agreement-badge--no">Belum setuju</span>@endif <time datetime="{{ $message->created_at?->toIso8601String() }}">{{ $message->created_at?->format('d M Y H:i') }}</time></div>
                            <p class="chat-content">{{ \Illuminate\Support\Str::limit($content, 320) }}</p>
                        </li>
                    @empty
                        <li>Belum ada pertukaran pesan tim pada run ini.</li>
                    @endforelse
                </ol>
                @if ($run->summary)<p class="sm-summary"><strong>Ringkasan Scrum Master:</strong> {{ \Illuminate\Support\Str::limit($run->summary, 600) }}</p>@endif
            </article>
        @empty
            <p>Belum ada diskusi Fibonacci untuk User Story ini.</p>
        @endforelse
    </section>
    <section aria-labelledby="execution-heading">
        <h2 id="execution-heading">Eksekusi &amp; Revisi QA</h2>
        <p class="notice-bar">Belum ada eksekusi atau revisi QA. Bagian ini tetap kosong sampai executor terisolasi dan bukti QA tersedia.</p>
    </section>
@endif
@endsection