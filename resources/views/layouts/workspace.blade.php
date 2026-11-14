<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <title>{{ $title ?? 'Workspace' }} · AI Scrum Agent</title>
    <script>
        // Terapkan tema tersimpan SEBELUM render untuk mencegah flash tema yang salah.
        (function () {
            try {
                var t = localStorage.getItem('scrumspace-theme');
                if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t);
            } catch (e) {}
        })();
    </script>
    <link rel="stylesheet" href="{{ asset('css/workspace.css') }}?v={{ filemtime(public_path('css/workspace.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/dialog.css') }}?v={{ filemtime(public_path('css/dialog.css')) }}">
    @if (in_array(($activePage ?? ''), ['agents', 'agent-activity', 'ai-providers'], true))
        <link rel="stylesheet" href="{{ asset('css/agents.css') }}?v={{ filemtime(public_path('css/agents.css')) }}">
    @endif
    @if (($activePage ?? '') === 'ai-providers')
        <link rel="stylesheet" href="{{ asset('css/ai-providers.css') }}?v={{ filemtime(public_path('css/ai-providers.css')) }}">
    @endif
    @if (request()->routeIs('projects.show', 'projects.index', 'projects.create', 'projects.executions.index', 'projects.release.show', 'projects.settings.show'))
        <link rel="stylesheet" href="{{ asset('css/project-tabs.css') }}?v={{ filemtime(public_path('css/project-tabs.css')) }}">
    @endif
    @if (request()->routeIs('projects.show', 'projects.release.show', 'projects.settings.show'))
        <link rel="stylesheet" href="{{ asset('css/project-board.css') }}?v={{ filemtime(public_path('css/project-board.css')) }}">
    @endif
    @if (request()->routeIs('stories.executions.show'))
        <link rel="stylesheet" href="{{ asset('css/execution.css') }}?v={{ filemtime(public_path('css/execution.css')) }}">
    @endif
    @if (in_array(($activePage ?? ''), ['chat', 'agent-chat', 'discussion'], true))
        <link rel="stylesheet" href="{{ asset('css/chat.css') }}?v={{ filemtime(public_path('css/chat.css')) }}">
    @endif
    @if (($activePage ?? '') === 'discussion')
        <link rel="stylesheet" href="{{ asset('css/discussion.css') }}?v={{ filemtime(public_path('css/discussion.css')) }}">
    @endif
    @if (route('dashboard') === url()->current())
        <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}?v={{ filemtime(public_path('css/dashboard.css')) }}">
        <link rel="stylesheet" href="{{ asset('css/backlog-board.css') }}?v={{ filemtime(public_path('css/backlog-board.css')) }}">
    @endif
    @if (in_array(($activePage ?? ''), ['backlog', 'sprints'], true))
        <link rel="stylesheet" href="{{ asset('css/sprints.css') }}?v={{ filemtime(public_path('css/sprints.css')) }}">
        <link rel="stylesheet" href="{{ asset('css/backlog-board.css') }}?v={{ filemtime(public_path('css/backlog-board.css')) }}">
    @endif
    @if (($activePage ?? '') === 'actors')
        <link rel="stylesheet" href="{{ asset('css/actors.css') }}?v={{ filemtime(public_path('css/actors.css')) }}">
    @endif
</head>
<body class="workspace-body">
    <div class="app-shell">
        <aside class="sidebar" aria-label="Navigasi utama">
            <a class="brand" href="{{ route('dashboard') }}">
                <span class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></span>
                <span class="brand-copy"><strong>scrumspace</strong><small>AI agent workspace</small></span>
            </a>

            <div class="workspace-switcher">
                <span class="workspace-symbol">A</span>
                <span><strong>Workspace utama</strong><small>Single Product Owner</small></span>
                <span class="switcher-chevron" aria-hidden="true">⌄</span>
            </div>

            @php
                $ap = $activePage ?? '';
                $is = fn (array $pages) => in_array($ap, $pages, true) ? 'is-active' : '';
            @endphp
            <nav class="nav-group" aria-label="Menu utama">
                <p class="nav-caption">UTAMA</p>
                <a class="nav-link {{ $is(['dashboard']) }}" href="{{ route('dashboard') }}"><span class="nav-dot"></span>Ringkasan</a>
                <a class="nav-link {{ $is(['projects']) }}" href="{{ route('projects.index') }}"><span class="nav-dot"></span>Projects</a>
            </nav>

            @if ($navProject)
                <nav class="nav-group nav-group-project" aria-label="Menu project">
                    <p class="nav-caption">PROJECT</p>
                    <label class="sr-only" for="navProjectSelect">Pilih project</label>
                    <select id="navProjectSelect" class="nav-project-select" data-url-template="{{ route('projects.show', ['project' => '__ID__']) }}">
                        @foreach ($navProjects as $option)
                            <option value="{{ $option->id }}" @selected($option->id === $navProject->id)>{{ $option->name }}</option>
                        @endforeach
                    </select>
                    <a class="nav-link {{ $is(['backlog', 'sprints', 'discussion', 'project-backlog']) }}" href="{{ route('projects.show', $navProject) }}"><span class="nav-dot"></span>Sprint Backlog</a>
                    <a class="nav-link {{ $is(['chat']) }}" href="{{ route('chat.index', ['project_id' => $navProject->id]) }}"><span class="nav-dot"></span>Chat Scrum Master</a>
                    <a class="nav-link {{ $is(['executions']) }}" href="{{ route('projects.executions.index', $navProject) }}"><span class="nav-dot"></span>Eksekusi
                        @if (! empty($navDecisionCount))<span class="nav-badge" title="Perlu keputusan Anda">{{ $navDecisionCount }}</span>@endif
                    </a>
                    <a class="nav-link {{ $is(['release', 'github-settings']) }}" href="{{ route('projects.release.show', $navProject) }}"><span class="nav-dot"></span>Demo &amp; Rilis</a>
                    <a class="nav-link {{ $is(['project-settings']) }}" href="{{ route('projects.settings.show', $navProject) }}"><span class="nav-dot"></span>Pengaturan Project</a>
                </nav>
            @endif

            <nav class="nav-group" aria-label="Pengaturan">
                <p class="nav-caption">PENGATURAN</p>
                <a class="nav-link {{ $is(['ai-providers']) }}" href="{{ route('ai-providers.index') }}"><span class="nav-dot"></span>Model AI</a>
                <a class="nav-link {{ $is(['agents', 'agent-chat', 'agent-activity']) }}" href="{{ route('agents.index') }}"><span class="nav-dot"></span>Tim Agen</a>
                <a class="nav-link {{ $is(['actors']) }}" href="{{ route('actors.index') }}"><span class="nav-dot"></span>Aktor</a>
            </nav>

            <div class="sidebar-bottom">
                <div class="runtime-indicator">
                    @if (isset($hermesConnected))
                        <span class="status-dot {{ $hermesConnected ? 'status-ready' : 'status-idle' }}"></span>
                        <span><strong>AI Agent {{ $hermesConnected ? 'terhubung' : 'belum terhubung' }}</strong><small>{{ $hermesConnected ? 'Chat Scrum Master aktif' : 'Chat belum tersedia' }}</small></span>
                    @else
                        <span class="status-dot status-idle"></span>
                        <span><strong>AI Agent</strong><small>Lihat status di Ringkasan</small></span>
                    @endif
                </div>
                <div class="user-row">
                    <span class="user-avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                    <span class="user-copy"><strong>{{ auth()->user()->name }}</strong><small>Product Owner</small></span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="icon-button" type="submit">Keluar</button>
                    </form>
                </div>
            </div>
        </aside>

        <main class="main-area">
            <header class="topbar">
                <div class="breadcrumbs"><span>AI Scrum Agent Workspace</span><span class="crumb-separator">/</span><strong>{{ $breadcrumb ?? 'Ringkasan' }}</strong></div>
                <div class="topbar-right"><a class="topbar-bell {{ ($activePage ?? '') === 'notifications' ? 'is-active' : '' }}" href="{{ route('notifications.index') }}" title="Notifikasi" aria-label="Notifikasi{{ ! empty($unreadNotificationsCount) ? ', '.$unreadNotificationsCount.' belum dibaca' : '' }}"><span aria-hidden="true">🔔</span>@if (! empty($unreadNotificationsCount))<span class="topbar-bell-count">{{ $unreadNotificationsCount > 99 ? '99+' : $unreadNotificationsCount }}</span>@endif</a><button type="button" class="theme-toggle" id="themeToggle" title="Ganti tema" aria-label="Ganti tema gelap/terang"><span class="theme-icon-moon" aria-hidden="true">🌙</span><span class="theme-icon-sun" aria-hidden="true">☀️</span></button><span class="environment-pill"><span class="status-dot status-idle"></span>Local preview</span><span class="topbar-date">MVP · tahap awal</span></div>
            </header>
            @php $aiQuota = \App\Support\Ai\AiQuota::current(); @endphp
            @if ($aiQuota)
                <div class="quota-banner" role="alert">
                    <span class="quota-banner-icon" aria-hidden="true">⚠</span>
                    <p>{{ \App\Support\Ai\AiQuota::message($aiQuota) }}</p>
                    <a class="button button-primary" href="{{ route('ai-providers.index') }}">Ganti model</a>
                </div>
            @endif
            <div class="page-content">
                @yield('content')
            </div>
        </main>
    </div>

    @include('components.confirm-dialog')
    @include('components.activity-dock')
    <script src="{{ asset('js/confirm-dialog.js') }}?v={{ filemtime(public_path('js/confirm-dialog.js')) }}"></script>
    <script>
        // Pemilih project di sidebar: pindah ke Sprint Backlog project terpilih.
        (function () {
            var select = document.getElementById('navProjectSelect');
            if (!select) return;
            select.addEventListener('change', function () {
                window.location.href = select.dataset.urlTemplate.replace('__ID__', select.value);
            });
        })();
    </script>
    <script>
        // Toggle tema gelap/terang — preferensi disimpan di localStorage.
        (function () {
            var btn = document.getElementById('themeToggle');
            if (!btn) return;
            btn.addEventListener('click', function () {
                var current = document.documentElement.getAttribute('data-theme');
                var next = current === 'light' ? 'dark' : 'light';
                if (next === 'dark') {
                    document.documentElement.removeAttribute('data-theme');
                } else {
                    document.documentElement.setAttribute('data-theme', next);
                }
                try { localStorage.setItem('scrumspace-theme', next); } catch (e) {}
            });
        })();
    </script>
</body>
</html>
