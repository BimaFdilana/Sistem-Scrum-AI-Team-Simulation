{{-- Satu run diskusi Fibonacci: hasil, galat agen, lalu percakapan lengkap per putaran. --}}
@php
    $isCurrentRevision = (int) $run->story_revision === (int) $story->revision;
    $isOwner = (int) $run->requested_by === (int) auth()->id();
    $failedAgent = $run->agentRuns->where('status', 'FAILED')->sortByDesc('id')->first();
    $failureText = $failedAgent ? match ($failedAgent->error_code) {
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
    } : null;
    $roleName = fn (string $slug) => match ($slug) {
        'backend-developer' => 'Backend Developer',
        'frontend-developer' => 'Frontend Developer',
        'qa-engineer' => 'QA Engineer',
        default => ucwords(str_replace('-', ' ', $slug)),
    };
    $roleShort = fn (string $slug) => match ($slug) { 'backend-developer' => 'BE', 'frontend-developer' => 'FE', 'qa-engineer' => 'QA', default => strtoupper(substr($slug, 0, 2)) };
    $rounds = $run->messages->sortBy('id')->groupBy('round');
@endphp
<article class="dc-run" aria-label="Run {{ $run->id }}">
    <header class="dc-run-head">
        <div><span class="dc-run-id">Run #{{ $run->id }}</span> <span class="dc-hint">revisi {{ $run->story_revision }} · {{ $run->created_at?->format('d M Y H:i') }}</span></div>
        @if (in_array($run->status, ['FAILED', 'PARTIAL', 'NO_CONSENSUS'], true) && $isOwner && $isCurrentRevision)
            <form method="POST" action="{{ route('stories.estimations.retry', [$story, $run]) }}">@csrf<button class="button button-ghost" type="submit">Ulangi diskusi (run baru)</button></form>
        @endif
    </header>

    @if (! $isCurrentRevision)
        <p class="dc-banner is-warn">Run ini kedaluwarsa karena User Story telah berubah.</p>
    @endif

    @if ($run->status === 'COMPLETED' && $run->consensus_points)
        <div class="dc-result">
            <div class="dc-result-points"><span>{{ $run->consensus_points }}</span><small>poin · {{ \App\Services\StoryEstimationService::category($run->consensus_points) }}</small></div>
            <p>{{ $run->consensus_method === 'deadlock_max' ? 'Tim tidak mufakat setelah dua putaran, sehingga Scrum Master menerapkan nilai maksimum (estimasi paling aman).' : 'Mufakat bulat: Backend, Frontend, dan QA menyepakati poin yang sama.' }}</p>
        </div>
    @elseif ($run->status === 'NO_CONSENSUS')
        <p class="dc-banner is-warn"><strong>Tanpa konsensus.</strong> Bukti final tidak lengkap; run ini tidak menghasilkan poin.</p>
    @elseif (in_array($run->status, ['FAILED', 'PARTIAL'], true))
        <p class="dc-banner is-warn">Diskusi berhenti sebelum selesai. Gunakan "Ulangi diskusi" untuk membuat run baru.</p>
    @elseif (in_array($run->status, ['QUEUED', 'RUNNING', 'REPORT_PENDING'], true))
        <p class="dc-banner">Diskusi sedang berjalan. Pantau progresnya di panel mengambang kanan bawah; halaman ini perlu dimuat ulang untuk melihat pesan baru.</p>
    @endif

    @if ($failedAgent)
        <p class="dc-banner is-warn"><strong>{{ $roleName($failedAgent->profile_slug) }} · putaran {{ $failedAgent->round }}:</strong> {{ $failureText }}</p>
        @if ($run->status === 'FAILED' && $isOwner && $isCurrentRevision)
            <form method="POST" action="{{ route('stories.estimations.agents.retry', [$story, $run, $failedAgent]) }}">@csrf<button class="button button-ghost" type="submit">Ulangi {{ $roleName($failedAgent->profile_slug) }} saja</button></form>
        @endif
    @endif

    @if ($run->summary)
        <section class="dc-summary" aria-label="Ringkasan Scrum Master">
            <h3>Ringkasan Scrum Master</h3>
            <div class="dc-text">{{ $run->summary }}</div>
        </section>
    @endif

    <div class="dc-thread" aria-label="Pesan Run {{ $run->id }}">
        @forelse ($rounds as $round => $messages)
            <div class="dc-round">
                <span class="dc-round-title">Putaran {{ $round }} · {{ (int) $round === 1 ? 'usulan awal' : 'tanggapan dan kesepakatan' }}</span>
                <span class="dc-round-votes">{{ $messages->map(fn ($m) => $roleShort($m->sender_slug).' '.($m->estimate_points ?? '—'))->implode(' · ') }}</span>
            </div>
            @foreach ($messages as $message)
                @php
                    $payload = json_decode((string) $message->content, true);
                    $structured = is_array($payload);
                    $rationale = $structured ? trim((string) ($payload['rationale'] ?? '')) : '';
                    $response = $structured ? trim((string) ($payload['response'] ?? '')) : '';
                    $plain = $structured ? '' : trim((string) $message->content);
                    $roleKey = match ($message->sender_slug) { 'backend-developer' => 'be', 'frontend-developer' => 'fe', 'qa-engineer' => 'qa', default => 'sm' };
                @endphp
                <article class="dc-msg dc-msg--{{ $roleKey }}">
                    <span class="dc-avatar" aria-hidden="true">{{ $roleShort($message->sender_slug) }}</span>
                    <div class="dc-bubble">
                        <header>
                            <strong>{{ $roleName($message->sender_slug) }}</strong>
                            @if ($message->estimate_points)<span class="dc-chip">{{ $message->estimate_points }} poin</span>@endif
                            @if ($message->explicit_agree === true)<span class="dc-chip is-ok">setuju</span>@elseif ($message->explicit_agree === false && (int) $round > 1)<span class="dc-chip is-warn">belum setuju</span>@endif
                            <time datetime="{{ $message->created_at?->toIso8601String() }}">{{ $message->created_at?->format('H:i') }}</time>
                        </header>
                        @if ($rationale !== '')<div class="dc-part"><span class="dc-label">Alasan</span><div class="dc-text">{{ $rationale }}</div></div>@endif
                        @if ($response !== '')<div class="dc-part"><span class="dc-label">Tanggapan ke tim</span><div class="dc-text">{{ $response }}</div></div>@endif
                        @if ($plain !== '')<div class="dc-text">{{ $plain }}</div>@endif
                        @if ($rationale === '' && $response === '' && $plain === '')<p class="dc-hint">Pesan tidak memiliki isi teks.</p>@endif
                    </div>
                </article>
            @endforeach
        @empty
            <div class="dc-empty is-small"><span class="dc-empty-mark">✦</span><p>Belum ada pesan. Pesan muncul setelah worker memproses agent.</p></div>
        @endforelse
    </div>
</article>
