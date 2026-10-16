@extends('layouts.workspace', ['title' => 'Notifikasi', 'breadcrumb' => 'Notifikasi'])

@section('content')
    <section class="page-heading">
        <div>
            <p class="eyebrow"><span class="eyebrow-line"></span>WORKSPACE</p>
            <h1>Notifikasi</h1>
            <p class="page-subtitle">Pemberitahuan penting dari sistem — mis. patch agent yang ditolak atau eksekusi yang gagal.</p>
        </div>
        <div class="heading-actions">
            @if ($notifications->whereNull('read_at')->count() > 0)
                <form method="POST" action="{{ route('notifications.read-all') }}">
                    @csrf
                    <button class="button" type="submit">Tandai semua dibaca</button>
                </form>
            @endif
        </div>
    </section>

    @if (session('status'))
        <p class="success-banner">{{ session('status') }}</p>
    @endif

    @if ($notifications->isEmpty())
        <section class="empty-panel">
            <p class="section-kicker">KOSONG</p>
            <p>Belum ada notifikasi. Kamu akan diberi tahu di sini bila ada patch agent yang ditolak atau eksekusi yang bermasalah.</p>
        </section>
    @else
        <section class="agent-directory-list">
            @foreach ($notifications as $notification)
                <article class="directory-row {{ $notification->read_at ? '' : 'directory-row-unread' }}">
                    <span class="agent-avatar {{ $notification->read_at ? '' : 'avatar-amber' }}">!</span>
                    <div class="directory-main">
                        <h2>{{ $notification->title }}</h2>
                        <p>{{ $notification->message }}</p>
                        <p><small>{{ $notification->created_at->diffForHumans() }}</small></p>
                    </div>
                    <div class="directory-role">
                        @if ($notification->link)
                            <a class="text-link" href="{{ $notification->link }}">Lihat detail<span>→</span></a>
                        @endif
                    </div>
                    <div>
                        @if (! $notification->read_at)
                            <form method="POST" action="{{ route('notifications.read', $notification) }}">
                                @csrf
                                <button class="text-link" type="submit">Tandai dibaca</button>
                            </form>
                        @else
                            <span class="soft-badge">Dibaca</span>
                        @endif
                    </div>
                </article>
            @endforeach
        </section>

        <div style="margin-top: 16px;">
            {{ $notifications->links() }}
        </div>
    @endif
@endsection
