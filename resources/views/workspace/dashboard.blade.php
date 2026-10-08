@extends('layouts.workspace', ['title' => 'Ringkasan', 'breadcrumb' => 'Ringkasan'])

@section('content')
    <section class="page-heading dashboard-heading">
        <div>
            <p class="eyebrow"><span class="eyebrow-line"></span>WORKSPACE OVERVIEW</p>
            <h1>Selamat datang, {{ auth()->user()->name }}.</h1>
            <p class="page-subtitle">Ruang kendali Scrum Anda. Agent mengusulkan dan mengerjakan; Anda tetap memegang keputusan.</p>
        </div>
        <div class="heading-actions"><span class="setup-chip"><span class="setup-ring">1</span>Setup awal</span><a class="button button-primary" href="{{ route('projects.create') }}">＋ Buat project</a></div>
    </section>

    <section class="notice-bar" aria-label="Status integrasi">
        <span class="notice-icon">i</span>
        @if ($hermesConnected)
            <p><strong>AI Agent terhubung.</strong> Chat workspace sudah aktif; eksekusi task tetap menunggu verifikasi isolasi.</p>
            <a class="text-link" href="{{ route('chat.index') }}">Buka chat →</a>
        @else
            <p><strong>AI Agent belum terhubung.</strong> Chat belum tersedia; silakan hubungi administrator workspace.</p>
            <span class="notice-state">MENUNGGU KONFIGURASI</span>
        @endif
    </section>

    <section class="section-block" id="agents">
        <div class="section-heading">
            <div><p class="section-kicker">YOUR AI TEAM</p><h2>Agent workspace</h2><p class="section-description">Setiap role dirancang punya konteks, memory, dan histori sendiri.</p></div>
            <a class="text-link" href="{{ route('agents.index') }}">Lihat semua agent <span aria-hidden="true">→</span></a>
        </div>
        <div class="agent-grid">
            @foreach ($agents as $agent)
                <article class="agent-card">
                    @php($chatAvailable = $agent['slug'] === 'scrum-master' && $hermesConnected)
                    <div class="agent-card-top">
                        <span class="agent-avatar avatar-{{ $agent['tone'] }}">{{ $agent['initials'] }}</span>
                        <span class="agent-status"><i></i>{{ $chatAvailable ? 'Chat aktif' : 'Belum terhubung' }}</span>
                    </div>
                    <div class="agent-identity"><h3>{{ $agent['name'] }}</h3><span>{{ $agent['role'] }}</span></div>
                    <p class="agent-description">{{ $agent['description'] }}</p>
                    <div class="agent-card-footer"><span>Profile <code>{{ $agent['profile'] }}</code></span><span class="agent-arrow" aria-hidden="true">↗</span></div>
                </article>
            @endforeach
        </div>
    </section>

    <section class="lower-grid">
        <article class="empty-panel">
            <div class="panel-heading"><div><p class="section-kicker">CURRENT SPRINT</p><h2>Belum ada sprint</h2></div><span class="soft-badge">Belum dimulai</span></div>
            <p>Buat project dan backlog terlebih dahulu. Scrum Master dapat membantu refinement setelah kebutuhan awal tersedia.</p>
            <div class="empty-actions"><span class="empty-icon">＋</span><span><strong>Mulai dari project</strong><small>Project baru atau source code existing</small></span></div>
        </article>
        <article class="empty-panel approvals-panel">
            <div class="panel-heading"><div><p class="section-kicker">HUMAN IN THE LOOP</p><h2>Approval PO</h2></div><span class="approval-count">Belum ada</span></div>
            <p>Hasil agent menunggu QA dan keputusan Anda. Tidak ada perubahan scope, merge, atau deploy otomatis.</p>
            <div class="approval-rule"><span class="rule-check">✓</span><span>QA PASS tetap memerlukan acceptance manusia</span></div>
        </article>
    </section>

    <footer class="page-footer"><span>AI Scrum Agent Workspace <span class="footer-separator">·</span> MVP build in progress</span><span>AI Agent {{ $hermesConnected ? 'terhubung' : 'belum terhubung' }}</span></footer>
@endsection
