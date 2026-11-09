@extends('layouts.workspace', ['title' => $project->name, 'breadcrumb' => 'Projects / '.$project->name])

@section('content')
    <section class="page-heading">
        <div>
            <p class="eyebrow"><span class="eyebrow-line"></span>PROJECT OVERVIEW</p>
            <h1>{{ $project->name }}</h1>
            <p class="page-subtitle">{{ $project->description ?: 'Belum ada deskripsi project.' }}</p>
        </div>
        <a class="text-link" href="{{ route('projects.index') }}">← Semua projects</a>
    </section>

    @if (session('status'))
        <div class="success-banner" role="status">{{ session('status') }}</div>
    @endif

    <div class="pj-tabs" data-tabs>
        <div class="pj-tabbar" role="tablist" aria-label="Bagian project {{ $project->name }}">
            <button type="button" class="pj-tab is-active" data-tab="ringkasan" role="tab">Ringkasan</button>
            <button type="button" class="pj-tab" data-tab="demo" role="tab">
                Demo
                @if ($demo)<span class="pj-flag is-live">port {{ $demo->port }}</span>@else<span class="pj-flag">mati</span>@endif
            </button>
            <button type="button" class="pj-tab" data-tab="backlog" role="tab">
                Backlog
                <span class="pj-flag is-info">{{ $project->stories_count }} story</span>
            </button>
            <button type="button" class="pj-tab" data-tab="tes" role="tab">
                Gerbang Tes
                @if ($project->test_command)<span class="pj-flag is-ok">aktif</span>@else<span class="pj-flag">kosong</span>@endif
            </button>
        </div>

        <div class="pj-panel is-active" data-panel="ringkasan" role="tabpanel">
            <section class="project-detail-grid">
                <article class="empty-panel">
                    <div class="panel-heading"><div><p class="section-kicker">PROJECT TYPE</p><h2>{{ $project->project_type === 'NEW_PROJECT' ? 'Project baru' : 'Project existing' }}</h2></div><span class="soft-badge">{{ str_replace('_', ' ', $project->status) }}</span></div>
                    <p>{{ $project->stack ? 'Stack awal: '.$project->stack : 'Stack belum ditentukan.' }}</p>
                    <div class="approval-rule"><span class="rule-check">✓</span><span>Belum ada agent yang diberi izin mengubah source project ini.</span></div>
                </article>
                <article class="empty-panel">
                    <div class="panel-heading"><div><p class="section-kicker">SOURCE BASELINE</p><h2>{{ $project->source_repository_url ? 'GitHub repository' : 'Laravel baru' }}</h2></div><span class="soft-badge">{{ $project->source_repository_url ? 'Public · private baseline' : 'Scaffold otomatis' }}</span></div>
                    @if ($project->source_repository_url)
                        <p class="archive-name">{{ $project->source_repository_url }}</p>
                        <div class="archive-meta"><span>Branch: {{ $project->source_branch ?: 'default branch' }}</span><span>Commit: <code>{{ $project->source_commit_sha ?: 'pending' }}</code></span></div>
                    @else
                        <p class="archive-name">Kerangka Laravel baru dibuat otomatis oleh sistem.</p>
                    @endif
                    @if ($project->source_error)
                        <div class="approval-rule"><span class="status-dot status-danger"></span><span>{{ $project->source_error }}</span></div>
                    @else
                        <div class="approval-rule"><span class="status-dot status-idle"></span><span>Status {{ str_replace('_', ' ', $project->status) }} · tidak ada kode yang dijalankan</span></div>
                    @endif
                </article>
            </section>
        </div>

        <div class="pj-panel" data-panel="demo" role="tabpanel">
            <section class="empty-panel">
                <div class="panel-heading"><div><p class="section-kicker">DEMO PROJECT</p><h2>Pratinjau sementara</h2></div>
                @if ($demo)
                    <span class="soft-badge">Berjalan · port {{ $demo->port }}</span>
                @endif
                </div>
                @if ($demo && $demoUrl)
                    <p>Demo aktif di <a href="{{ $demoUrl }}" target="_blank" rel="noopener">{{ $demoUrl }}</a> · berhenti otomatis {{ $demo->expires_at?->format('H:i') }}.</p>
                    <form method="POST" action="{{ route('projects.demo.stop', $project) }}">@csrf<button class="button button-ghost" type="submit">Hentikan demo</button></form>
                    <form method="POST" action="{{ route('projects.demo.request-fix', $project) }}" style="margin-top:8px">@csrf<button class="button button-ghost" type="submit" onclick="return handleConfirm(event, {title: 'Minta SM perbaiki demo?', message: 'Minta Scrum Master memperbaiki demo yang bermasalah?', confirmLabel: 'Ya, minta perbaiki'})">Minta SM Perbaiki</button></form>
                @else
                    <p>Jalankan project di server demo sementara (port unik per project).</p>
                    <form method="POST" action="{{ route('projects.demo.start', $project) }}">@csrf<button class="button button-primary" type="submit">Jalankan demo</button></form>
                @endif
                <p class="demo-live" id="demo-live" data-status-url="{{ route('projects.demo.status', $project) }}">
                    Status: <strong>memuat…</strong>
                </p>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:12px">
                    <a class="button button-ghost" href="{{ route('projects.download', $project) }}">Download project (ZIP)</a>
                    <form method="POST" action="{{ route('projects.demo.rebuild', $project) }}">@csrf<button class="button button-ghost" type="submit">Bangun ulang aset (CSS/JS)</button></form>
                </div>
            </section>
        </div>

        <div class="pj-panel" data-panel="backlog" role="tabpanel">
            <section class="backlog-launch empty-panel">
                <div class="panel-heading"><div><p class="section-kicker">SPRINT BACKLOG</p><h2>{{ $project->stories_count }} User Story</h2></div><span class="soft-badge">PO approval required</span></div>
                <p>Satu Sprint Backlog untuk project ini. Buat beberapa Sprint dan susun User Story di dalam Sprint masing-masing.</p>
                <a class="button button-primary" href="{{ route('projects.sprint-backlog.index', $project) }}">Buka Sprint Backlog <span aria-hidden="true">→</span></a>
            </section>
        </div>

        <div class="pj-panel" data-panel="tes" role="tabpanel">
            <section class="empty-panel">
                <div class="panel-heading"><div><p class="section-kicker">GERBANG TES QA</p><h2>Perintah tes project (opsional)</h2></div></div>
                <p>Sebelum review QA, gerbang selalu menjalankan <code>php -l</code> untuk file PHP yang berubah. Perintah di bawah ini bersifat opsional dan dijalankan di worktree task bila diisi; kosong berarti tidak dijalankan. Contoh: <code>php artisan test</code> atau <code>vendor/bin/phpunit</code>. Approval QA tidak bisa meng-override gerbang yang gagal.</p>
                <form method="POST" action="{{ route('projects.test-command.update', $project) }}" style="display:flex;gap:.5rem;flex-wrap:wrap">
                    @csrf
                    @method('PUT')
                    <input type="text" name="test_command" value="{{ old('test_command', $project->test_command) }}" placeholder="Kosong = gerbang tes mati" style="flex:1;min-width:260px">
                    <button class="button button-primary" type="submit">Simpan perintah tes</button>
                </form>
                @error('test_command')<p style="color:#b91c1c">{{ $message }}</p>@enderror
            </section>
        </div>
    </div>

    @once
    <script>
        // Polling status demo: server bisa mati/kedaluwarsa tanpa reload halaman.
        (function () {
            var el = document.getElementById('demo-live');
            if (!el) return;
            var url = el.dataset.statusUrl;

            function render(data) {
                if (!data || !data.running) {
                    el.innerHTML = 'Status: <strong>tidak berjalan</strong>';
                    return;
                }
                var health = data.health === 'OK' ? 'sehat' : (data.health || 'belum dicek');
                el.innerHTML = 'Status: <strong>berjalan di port ' + data.port + '</strong>'
                    + ' · ' + health + (data.http ? ' (HTTP ' + data.http + ')' : '')
                    + (data.expires_at ? ' · berhenti ' + data.expires_at : '')
                    + (data.error ? ' · <span style="color:#fca5a5">' + data.error + '</span>' : '');
            }

            function poll() {
                fetch(url, { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(render)
                    .catch(function () {
                        el.innerHTML = 'Status: <strong>tidak dapat dibaca</strong>';
                    });
            }

            poll();
            setInterval(poll, 8000);
        })();
    </script>
    <script>
        document.addEventListener('click', function (event) {
            var button = event.target.closest('[data-tab]');
            if (!button) return;
            var root = button.closest('[data-tabs]');
            if (!root) return;
            root.querySelectorAll('[data-tab]').forEach(function (tab) {
                tab.classList.toggle('is-active', tab === button);
                tab.setAttribute('aria-selected', tab === button ? 'true' : 'false');
            });
            root.querySelectorAll('[data-panel]').forEach(function (panel) {
                panel.classList.toggle('is-active', panel.dataset.panel === button.dataset.tab);
            });
        });
    </script>
    @endonce
@endsection
