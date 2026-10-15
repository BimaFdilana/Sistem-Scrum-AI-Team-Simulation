<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Workspace' }} · AI Scrum Agent</title>
    <link rel="stylesheet" href="{{ asset('css/workspace.css') }}">
    @if (in_array(($activePage ?? ''), ['agents', 'agent-activity', 'ai-providers'], true))
        <link rel="stylesheet" href="/css/agents.css">
    @endif
    @if (in_array(($activePage ?? ''), ['chat', 'agent-chat', 'discussion'], true))
        <link rel="stylesheet" href="{{ asset('css/chat.css') }}">
    @endif
    @if (($activePage ?? '') === 'discussion')
        <link rel="stylesheet" href="{{ asset('css/discussion.css') }}">
    @endif
    @if (in_array(($activePage ?? ''), ['backlog', 'sprints'], true))
        <link rel="stylesheet" href="{{ asset('css/sprints.css') }}">
    @endif
    @if (($activePage ?? '') === 'actors')
        <link rel="stylesheet" href="{{ asset('css/actors.css') }}">
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

            <nav class="nav-group">
                <p class="nav-caption">WORKSPACE</p>
                <a class="nav-link {{ $activePage === 'dashboard' ? 'is-active' : '' }}" href="{{ route('dashboard') }}"><span class="nav-dot"></span>Ringkasan</a>
                <a class="nav-link {{ in_array($activePage, ['agents', 'agent-chat'], true) ? 'is-active' : '' }}" href="{{ route('agents.index') }}"><span class="nav-dot"></span>Agent</a>
                <a class="nav-link {{ $activePage === 'ai-providers' ? 'is-active' : '' }}" href="{{ route('ai-providers.index') }}"><span class="nav-dot"></span>AI Providers</a>
                <a class="nav-link {{ $activePage === 'projects' ? 'is-active' : '' }}" href="{{ route('projects.index') }}"><span class="nav-dot"></span>Projects</a>
                <a class="nav-link {{ $activePage === 'actors' ? 'is-active' : '' }}" href="{{ route('actors.index') }}"><span class="nav-dot"></span>Aktor</a>
                <a class="nav-link {{ in_array($activePage, ['backlog', 'sprints'], true) ? 'is-active' : '' }}" href="{{ route('projects.index', ['section' => 'sprint-backlog']) }}"><span class="nav-dot"></span>Sprint Backlog</a>
                <a class="nav-link {{ $activePage === 'repair-orders' ? 'is-active' : '' }}" href="{{ route('repair-orders.index') }}"><span class="nav-dot"></span>Perintah Perbaikan</a>
                <a class="nav-link {{ $activePage === 'accepted' ? 'is-active' : '' }}" href="{{ route('accepted.index') }}"><span class="nav-dot"></span>Accepted</a>
                <a class="nav-link {{ $activePage === 'github-settings' ? 'is-active' : '' }}" href="{{ route('settings.github.edit') }}"><span class="nav-dot"></span>GitHub</a>
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
                <div class="topbar-right"><span class="environment-pill"><span class="status-dot status-idle"></span>Local preview</span><span class="topbar-date">MVP · tahap awal</span></div>
            </header>
            <div class="page-content">
                @yield('content')
            </div>
        </main>
    </div>
</body>
</html>
