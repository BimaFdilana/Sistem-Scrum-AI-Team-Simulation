@extends('layouts.workspace', ['title' => 'Diskusi Tim · '.$story->display_id, 'breadcrumb' => 'Projects / '.$story->project->name.' / Diskusi Tim'])

@section('content')
<style>
    .discussion-run { margin: 1rem 0 1.5rem; }
    .discussion-status { display:flex; justify-content:space-between; gap:1rem; align-items:center; flex-wrap:wrap; }
    .discussion-banner { border-radius:.75rem; padding:.75rem 1rem; margin:.75rem 0; background:#eef6ff; }
    .discussion-banner--danger { background:#fff1f2; color:#9f1239; }
    .chat-list { list-style:none; padding:0; margin:1rem 0; display:grid; gap:.65rem; }
    .chat-bubble { border:1px solid #e5e7eb; border-left:4px solid #64748b; border-radius:.7rem; padding:.7rem .85rem; background:#fff; }
    .chat-bubble--backend { border-left-color:#2563eb; } .chat-bubble--frontend { border-left-color:#7c3aed; } .chat-bubble--qa { border-left-color:#059669; }
    .chat-meta { display:flex; gap:.55rem; align-items:center; flex-wrap:wrap; font-size:.82rem; color:#64748b; }
    .chat-role { font-weight:700; color:#111827; } .round-badge,.agreement-badge { border-radius:999px; padding:.12rem .45rem; background:#f1f5f9; }
    .agreement-badge--yes { background:#dcfce7; color:#166534; } .agreement-badge--no { background:#fee2e2; color:#991b1b; }
    .chat-content { margin:.4rem 0 0; white-space:pre-wrap; overflow-wrap:anywhere; }
    .sm-summary { border-left:3px solid #f59e0b; padding:.55rem .8rem; background:#fffbeb; }
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
                @if ($run->story_revision !== $story->revision)<p class="estimation-stale">Kedaluwarsa: story telah berubah.</p>@endif
                @if ($run->status === 'COMPLETED' && $run->consensus_points)
                    <p class="discussion-banner"><strong>Poin Fibonacci final: {{ $run->consensus_points }}</strong> · Laporan Scrum Master tersimpan.</p>
                @elseif ($run->status === 'NO_CONSENSUS')
                    <p class="discussion-banner discussion-banner--danger"><strong>NO_CONSENSUS</strong> · Tiga putaran selesai tanpa tiga persetujuan eksplisit yang sama. Tidak ada poin final.</p>
                @else
                    <p class="discussion-banner">Belum ada poin final yang dapat dieksekusi.</p>
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