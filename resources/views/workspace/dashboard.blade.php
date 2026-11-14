@extends('layouts.workspace', ['title' => 'Ringkasan', 'breadcrumb' => 'Ringkasan'])

@section('content')
    <section class="page-heading dashboard-heading">
        <div>
            <p class="eyebrow"><span class="eyebrow-line"></span>WORKSPACE OVERVIEW</p>
            <h1>Selamat datang, {{ auth()->user()->name }}.</h1>
            <p class="page-subtitle">Ruang kendali Scrum Anda. Agent mengusulkan dan mengerjakan; Anda tetap memegang keputusan.</p>
        </div>
        <div class="heading-actions">
            <a class="button button-ghost" href="{{ route('projects.index') }}">Semua project</a>
            <a class="button button-primary" href="{{ route('projects.create') }}">＋ Buat project</a>
        </div>
    </section>

    @if ($hermesConnected)
        <section class="notice-bar" aria-label="Status integrasi">
            <span class="notice-icon">✓</span>
            <p><strong>AI Agent terhubung.</strong> Chat workspace aktif; agent menunggu perintah Anda.</p>
            <a class="text-link" href="{{ route('chat.index') }}?from=dashboard">Buka chat →</a>
        </section>
    @else
        <section class="notice-bar" aria-label="Status integrasi">
            <span class="notice-icon">i</span>
            <p><strong>AI Agent belum terhubung.</strong> Chat belum tersedia; hubungkan provider di menu AI Providers.</p>
            <span class="notice-state">MENUNGGU KONFIGURASI</span>
        </section>
    @endif

    <div class="dash-stats">
        <article class="dash-stat">
            <span class="dash-stat-label">Story</span>
            <strong class="dash-stat-value">{{ $stats['stories'] }}</strong>
            <span class="dash-stat-hint">{{ $stats['projects'] }} project · {{ $stats['points'] }} poin</span>
            <div class="dash-stat-bar"><span style="width: {{ $stats['stories'] > 0 ? (int) round($stats['accepted'] / $stats['stories'] * 100) : 0 }}%"></span></div>
        </article>

        <article class="dash-stat dash-stat--ok">
            <span class="dash-stat-label">Diterima PO</span>
            <strong class="dash-stat-value">{{ $stats['accepted'] }}</strong>
            <span class="dash-stat-hint">{{ $stats['stories'] > 0 ? (int) round($stats['accepted'] / $stats['stories'] * 100) : 0 }}% dari seluruh story</span>
            <div class="dash-stat-bar"><span style="width: {{ $stats['stories'] > 0 ? (int) round($stats['accepted'] / $stats['stories'] * 100) : 0 }}%"></span></div>
        </article>

        <article class="dash-stat dash-stat--live">
            <span class="dash-stat-label">Eksekusi berjalan</span>
            <strong class="dash-stat-value">{{ $stats['active_runs'] }}</strong>
            <span class="dash-stat-hint">{{ $stats['active_runs'] > 0 ? 'agent sedang bekerja' : 'tidak ada run aktif' }}</span>
            <div class="dash-stat-bar"><span style="width: {{ $stats['active_runs'] > 0 ? 65 : 0 }}%"></span></div>
        </article>

        <article class="dash-stat dash-stat--warn">
            <span class="dash-stat-label">Izin menunggu</span>
            <strong class="dash-stat-value">{{ $stats['pending_permissions'] }}</strong>
            <span class="dash-stat-hint">{{ $stats['pending_permissions'] > 0 ? 'butuh keputusan Anda' : 'tidak ada permintaan' }}</span>
            <div class="dash-stat-bar"><span style="width: {{ $stats['pending_permissions'] > 0 ? 100 : 0 }}%"></span></div>
        </article>
    </div>

    @include('partials.story-board', ['boardStories' => $boardStories, 'showProject' => true])

    <div class="dash-lower">
        <section class="dash-panel">
            <div class="dash-panel-head">
                <h2>Tim agent</h2>
                <a class="text-link" href="{{ route('agents.index') }}">Lihat semua →</a>
            </div>
            <ul class="dash-agents">
                @foreach ($agents as $agent)
                    @php($chatAvailable = $agent['slug'] === 'scrum-master' && $hermesConnected)
                    <li>
                        @php($avatarFile = 'avatars/'.$agent['slug'].'.svg')
                        @if (file_exists(public_path($avatarFile)))
                            <img class="dash-agent-avatar" src="{{ asset($avatarFile) }}" alt="" width="32" height="32" loading="lazy">
                        @else
                            <span class="dash-agent-avatar avatar-{{ $agent['tone'] }}">{{ $agent['initials'] }}</span>
                        @endif
                        <span class="dash-agent-copy">
                            <strong>{{ $agent['name'] }}</strong>
                            <small>{{ $agent['role'] }}</small>
                        </span>
                        <span class="dash-agent-state {{ $chatAvailable ? 'is-on' : '' }}"><i></i>{{ $chatAvailable ? 'aktif' : 'siaga' }}</span>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="dash-panel">
            <div class="dash-panel-head">
                <h2>Aktivitas terakhir</h2>
                <span class="dash-panel-note">eksekusi & QA</span>
            </div>
            <ol class="dash-feed">
                @forelse ($activities as $message)
                    <li>
                        <span class="dash-feed-dot"></span>
                        <div>
                            <strong>{{ str_replace(['-', '_'], ' ', $message->sender_slug) }}</strong>
                            <p>{{ \Illuminate\Support\Str::limit(\App\Support\Ui\FriendlyText::clean($message->content), 110) }}</p>
                            <time>{{ $message->created_at?->diffForHumans() }}{{ $message->run?->story ? ' · '.$message->run->story->display_id : '' }}</time>
                        </div>
                    </li>
                @empty
                    <li class="dash-feed-empty">Belum ada aktivitas eksekusi.</li>
                @endforelse
            </ol>
        </section>
    </div>

    <footer class="page-footer"><span>AI Scrum Agent Workspace <span class="footer-separator">·</span> MVP build in progress</span><span>AI Agent {{ $hermesConnected ? 'terhubung' : 'belum terhubung' }}</span></footer>
@endsection
