@extends('layouts.workspace')

@section('content')
<div class="page-head">
    <div>
        <p class="section-kicker">EKSEKUSI USER STORY</p>
        <h1>{{ $story->display_id }} — {{ $story->effectiveTitle() }}</h1>
        <p>Estimasi: {{ $story->estimate_points }} poin · Versi: <strong>{{ $story->hasRefinement() ? 'Hasil Diskusi SM' : 'Data Asli' }}</strong> · Status: <strong>{{ str_replace('_', ' ', $run->status) }}</strong></p>
    </div>
    <a class="button button-ghost" href="{{ route('projects.sprint-backlog.index', $story->project) }}">← Kembali</a>
</div>

@if (session('status'))
    <div class="notice-bar"><span class="notice-icon">✓</span><p>{{ session('status') }}</p></div>
@endif
@if ($errors->any())
    <div class="notice-bar notice-danger"><span class="notice-icon">!</span><p>{{ $errors->first() }}</p></div>
@endif

@if ($story->isAccepted())
    <div class="notice-bar"><span class="notice-icon">✓</span><p><strong>Keputusan final PO: {{ $story->acceptanceLabel() }}</strong> ({{ $story->accepted_at?->format('d M Y H:i') }}). Story ini terkunci dan tidak bisa dieksekusi lagi.</p></div>
@elseif ($run->isTerminal())
    <div class="notice-bar"><span class="notice-icon">★</span><p><strong>Eksekusi selesai ({{ str_replace('_', ' ', $run->status) }}).</strong> Beri keputusan final sebagai Product Owner:</p></div>
    <form method="POST" action="{{ route('stories.executions.accept', [$story, $run]) }}" style="display:flex;gap:.5rem;margin-bottom:1rem">
        @csrf
        <button class="button button-primary" type="submit" name="status" value="BERHASIL" onclick="return confirm('Terima story ini sebagai BERHASIL? Story akan terkunci dari eksekusi ulang.')">Terima sebagai Berhasil</button>
        <button class="button button-danger" type="submit" name="status" value="GAGAL" onclick="return confirm('Tandai story ini sebagai GAGAL? Story akan terkunci dari eksekusi ulang.')">Tandai Gagal</button>
    </form>
@endif

@if ($run->status === 'AWAITING_PERMISSION')
    <p class="discussion-banner">Menunggu persetujuan PO di menu <a href="{{ route('accepted.index') }}">Accepted</a> sebelum agen mengubah code.</p>
@elseif ($run->status === 'COMPLETED')
    <p class="discussion-banner"><strong>Eksekusi selesai.</strong> {{ $run->summary }}</p>
@elseif ($run->status === 'BLOCKED')
    <p class="discussion-banner discussion-banner--danger"><strong>Eksekusi diblokir.</strong> {{ $run->summary }}</p>
@elseif ($run->status === 'FAILED')
    <p class="discussion-banner discussion-banner--danger"><strong>Eksekusi gagal.</strong> {{ $run->error_code }}</p>
@endif

<section class="empty-panel">
    <div class="panel-heading"><div><p class="section-kicker">TASK</p><h2>{{ $run->tasks->count() }} task dari Scrum Master</h2></div></div>
    <div class="task-list">
        @forelse ($run->tasks as $task)
            <div class="task-row">
                <div>
                    <strong>{{ $task->title }}</strong>
                    <small>{{ $task->assigneeName() }} · {{ str_replace('_', ' ', $task->status) }}@if($task->revision_count) · revisi QA: {{ $task->revision_count }}@endif</small>
                    <p>{{ $task->description }}</p>
                    @if ($task->qa_feedback && in_array($task->status, ['REVISION', 'BLOCKED'], true))
                        <p class="qa-feedback"><strong>Feedback QA:</strong> {{ $task->qa_feedback }}</p>
                    @endif
                </div>
            </div>
        @empty
            <p>Scrum Master sedang memecah story menjadi task…</p>
        @endforelse
    </div>
</section>

<section class="empty-panel">
    <div class="panel-heading"><div><p class="section-kicker">RIWAYAT TIM</p><h2>Diskusi &amp; activity log (read-only)</h2></div></div>
    <div class="chat-panel team-chat-panel" aria-label="Riwayat eksekusi">
        <div class="chat-transcript">
            @forelse ($run->messages as $message)
                <div class="team-message">
                    <div class="team-message-head"><strong>{{ str_replace(['-', '_'], ' ', $message->sender_slug) }}</strong><span>→ {{ str_replace(['-', '_'], ' ', $message->recipient_slug) }}</span><time>{{ $message->created_at->format('H:i') }}</time></div>
                    <p>{{ $message->content }}</p>
                </div>
            @empty
                <p>Belum ada activity.</p>
            @endforelse
        </div>
    </div>
</section>
@endsection
