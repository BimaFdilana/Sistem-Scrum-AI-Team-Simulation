@extends('layouts.workspace')

@section('content')
<div class="page-head">
    <div>
        <p class="section-kicker">ACCEPTED</p>
        <h1>Persetujuan izin agent</h1>
        <p>Agent meminta izin sebelum mengubah code. Setujui satu kali per eksekusi.</p>
    </div>
</div>

@if (session('status'))
    <div class="notice-bar"><span class="notice-icon">✓</span><p>{{ session('status') }}</p></div>
@endif
@if ($errors->any())
    <div class="notice-bar notice-danger"><span class="notice-icon">!</span><p>{{ $errors->first() }}</p></div>
@endif

<section class="empty-panel">
    @forelse ($requests as $request)
        <article class="approval-card">
            <div class="panel-heading">
                <div>
                    <h2>{{ $request->title }}</h2>
                    <p>Diminta oleh Scrum Master · {{ $request->created_at->format('d M Y H:i') }} · Status: <strong>{{ $request->status }}</strong></p>
                </div>
                @if ($request->status === 'PENDING')
                    <span class="soft-badge">Menunggu keputusan</span>
                @endif
            </div>
            @if (is_array($request->payload['tasks'] ?? null))
                <ul class="approval-task-list">
                    @foreach ($request->payload['tasks'] as $task)
                        <li>
                            <strong>{{ $task['title'] }}</strong> <small>({{ str_replace('-', ' ', $task['assignee'] ?? '') }})</small>
                            @if (! empty($task['files']))
                                <br><small>File: {{ implode(', ', array_slice($task['files'], 0, 6)) }}{{ count($task['files']) > 6 ? ', …' : '' }}</small>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            <div class="approval-actions">
                @if ($request->status === 'PENDING')
                    <form method="POST" action="{{ route('accepted.approve', $request) }}" style="display:inline">@csrf<button class="button button-primary" type="submit">Setujui</button></form>
                    <form method="POST" action="{{ route('accepted.reject', $request) }}" style="display:inline">@csrf<button class="button button-ghost" type="submit" onclick="return confirm('Tolak permintaan izin ini? Eksekusi akan dibatalkan.')">Tolak</button></form>
                @else
                    <small> diproses {{ $request->resolved_at?->format('d M Y H:i') }}</small>
                @endif
                <a class="button button-ghost" href="{{ route('stories.executions.show', [$request->run->story, $request->run]) }}">Lihat eksekusi</a>
            </div>
        </article>
    @empty
        <p>Belum ada permintaan izin.</p>
    @endforelse
</section>

{{ $requests->links() }}
@endsection
