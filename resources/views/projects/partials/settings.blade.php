{{-- Pengaturan project: baseline, nama backlog, demo, dan gerbang tes. --}}
<div class="bk-settings">
    <section class="empty-panel">
        <div class="panel-heading"><div><p class="section-kicker">SUMBER PROJECT</p><h2>{{ $project->source_repository_url ? 'GitHub repository' : 'Laravel baru' }}</h2></div></div>
        @if ($project->source_repository_url)
            <p class="archive-name">{{ $project->source_repository_url }}</p>
            <div class="archive-meta"><span>Branch: {{ $project->source_branch ?: 'default branch' }}</span><span>Commit: <code>{{ $project->source_commit_sha ?: 'pending' }}</code></span></div>
        @else
            <p class="archive-name">Kerangka Laravel baru dibuat otomatis oleh sistem.</p>
        @endif
        @if ($project->source_error)
            <div class="approval-rule"><span class="status-dot status-danger"></span><span>{{ $project->source_error }}</span></div>
        @endif
        <form class="bk-inline-form" method="POST" action="{{ route('projects.backlog.update', $project) }}">
            @csrf
            @method('PUT')
            <label for="backlog_name">Nama Sprint Backlog</label>
            <input id="backlog_name" name="name" value="{{ old('name', $backlog?->name) }}" maxlength="180" required>
            @error('name')<small class="field-error">{{ $message }}</small>@enderror
            <label for="backlog_description">Deskripsi Sprint Backlog</label>
            <textarea id="backlog_description" name="description" rows="2" maxlength="5000">{{ old('description', $backlog?->description) }}</textarea>
            @error('description')<small class="field-error">{{ $message }}</small>@enderror
            <button class="button button-ghost" type="submit">Simpan</button>
        </form>
    </section>

    <section class="empty-panel">
        <div class="panel-heading"><div><p class="section-kicker">DEMO</p><h2>Pratinjau sementara</h2></div>@if ($demo)<span class="soft-badge">Berjalan · port {{ $demo->port }}</span>@endif</div>
        @if ($demo && $demoUrl)
            <p>Demo aktif di <a href="{{ $demoUrl }}" target="_blank" rel="noopener">{{ $demoUrl }}</a> · berhenti otomatis {{ $demo->expires_at?->format('H:i') }}.</p>
            <form method="POST" action="{{ route('projects.demo.stop', $project) }}">@csrf<button class="button button-ghost" type="submit">Hentikan demo</button></form>

            @php($demoAccounts = $demo->credentials['accounts'] ?? [])
            @if ($demoAccounts !== [])
                <div class="bk-demo-accounts" aria-label="Akun masuk demo">
                    <h3>Akun untuk masuk ke demo</h3>
                    <p class="field-help">Kata sandi acak ini dibuat saat demo dinyalakan dan berlaku untuk semua akun di bawah. Hanya berlaku di database demo, bukan di project Anda.</p>
                    <div class="bk-pass">
                        <label for="demo-password" class="sr-only">Kata sandi demo</label>
                        <input id="demo-password" type="password" readonly value="{{ $demo->credentials['password'] }}" autocomplete="off">
                        <button class="button button-ghost" type="button" data-demo-pass-toggle aria-controls="demo-password">Tampilkan</button>
                        <button class="button button-ghost" type="button" data-demo-pass-copy>Salin</button>
                    </div>
                    <p class="field-help" data-demo-pass-note role="status" hidden></p>
                    <ul class="bk-account-list">
                        @foreach ($demoAccounts as $account)
                            <li>
                                <code>{{ $account['email'] }}</code>
                                <span>{{ $account['name'] ?: 'Tanpa nama' }}@if (filled($account['role'] ?? null)) · <strong>{{ $account['role'] }}</strong>@endif</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @elseif ($demo->credentials === null)
                <p class="field-help">Akun masuk dibuat saat demo dinyalakan. Demo ini dinyalakan sebelum fitur akun ada: hentikan lalu jalankan ulang demo untuk menampilkan akun dan kata sandinya.</p>
            @else
                <p class="field-help">Tidak ada akun yang bisa ditampilkan: database demo belum punya tabel pengguna dengan email dan password, atau seeder project belum mengisinya.</p>
            @endif
        @else
            <p>Jalankan project di server demo sementara (port unik per project).</p>
            <form method="POST" action="{{ route('projects.demo.start', $project) }}">@csrf<button class="button button-primary" type="submit">Jalankan demo</button></form>
        @endif
        @php($contents = $demoContents ?? ['branch' => null, 'accepted' => [], 'pending' => []])
        <div class="bk-demo-contents" aria-label="Isi demo">
            <h3>Isi demo</h3>
            <p class="field-help">Demo menyajikan <strong>{{ $contents['branch'] ?? '—' }}</strong>. Eksekusi yang sedang berjalan tidak pernah tampil di demo. Story yang sudah Anda terima harus ada di sini.</p>
            <ul class="bk-contents-list">
                @forelse ($contents['accepted'] as $item)
                    <li>
                        <span class="bk-chip {{ $item['included'] ? 'is-ok' : 'is-bad' }}">{{ $item['included'] ? 'ada di demo' : 'belum ada di demo' }}</span>
                        <span>{{ $item['label'] }}@if (! $item['included'])<small>Diterima, tetapi cabang {{ $item['branch'] }} belum tergabung ke kode demo.</small>@endif</span>
                    </li>
                @empty
                    <li><span class="field-help">Belum ada story yang diterima.</span></li>
                @endforelse
                @foreach ($contents['pending'] as $item)
                    <li>
                        <span class="bk-chip {{ $item['shown'] ? 'is-warn' : '' }}">{{ $item['shown'] ? 'tampil sementara' : 'belum tampil' }}</span>
                        <span>{{ $item['label'] }}<small>Selesai dikerjakan tetapi belum Anda terima; masuk demo setelah diterima.</small></span>
                    </li>
                @endforeach
            </ul>
        </div>
        <p class="demo-live" id="demo-live" data-status-url="{{ route('projects.demo.status', $project) }}">Status: <strong>memuat…</strong></p>
        <div class="bk-actions-row">
            <a class="button button-ghost" href="{{ route('projects.download', $project) }}">Download project (ZIP)</a>
            <form method="POST" action="{{ route('projects.demo.rebuild', $project) }}">@csrf<button class="button button-ghost" type="submit">Bangun ulang aset (CSS/JS)</button></form>
        </div>
    </section>

    <section class="empty-panel">
        <div class="panel-heading"><div><p class="section-kicker">GERBANG TES QA</p><h2>Perintah tes project (opsional)</h2></div>@if ($project->test_command)<span class="soft-badge">aktif</span>@endif</div>
        <p>Sebelum review QA, gerbang selalu menjalankan <code>php -l</code> untuk file PHP yang berubah. Perintah di bawah bersifat opsional dan dijalankan di worktree task bila diisi.</p>
        <form class="bk-inline-row" method="POST" action="{{ route('projects.test-command.update', $project) }}">
            @csrf
            @method('PUT')
            <input type="text" name="test_command" value="{{ old('test_command', $project->test_command) }}" placeholder="Kosong = gerbang tes mati">
            <button class="button button-primary" type="submit">Simpan</button>
        </form>
        @error('test_command')<p class="field-error">{{ $message }}</p>@enderror
    </section>
</div>

@once
<script>
    // Polling status demo: server bisa mati/kedaluwarsa tanpa reload halaman.
    (function () {
        var el = document.getElementById('demo-live');
        if (!el) return;
        var url = el.dataset.statusUrl;
        function render(data) {
            if (!data || !data.running) { el.innerHTML = 'Status: <strong>tidak berjalan</strong>'; return; }
            var health = data.health === 'OK' ? 'sehat' : (data.health || 'belum dicek');
            el.innerHTML = 'Status: <strong>berjalan di port ' + data.port + '</strong>'
                + ' · ' + health + (data.http ? ' (HTTP ' + data.http + ')' : '')
                + (data.expires_at ? ' · berhenti ' + data.expires_at : '')
                + (data.error ? ' · <span style="color:#fca5a5">' + data.error + '</span>' : '');
        }
        function poll() {
            if (document.hidden) return;
            fetch(url, { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(render)
                .catch(function () { el.innerHTML = 'Status: <strong>tidak dapat dibaca</strong>'; });
        }
        poll();
        setInterval(poll, 8000);
    })();
</script>
@endonce

@once
<script>
    // Kata sandi demo: tampilkan/sembunyikan dan salin.
    (function () {
        var input = document.getElementById('demo-password');
        if (!input) return;
        var note = document.querySelector('[data-demo-pass-note]');
        var say = function (text) { if (note) { note.textContent = text; note.hidden = false; } };
        document.querySelector('[data-demo-pass-toggle]').addEventListener('click', function (event) {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            event.currentTarget.textContent = show ? 'Sembunyikan' : 'Tampilkan';
        });
        document.querySelector('[data-demo-pass-copy]').addEventListener('click', function () {
            var done = function () { say('Kata sandi disalin.'); };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(input.value).then(done, function () { say('Gagal menyalin; tampilkan lalu salin manual.'); });
            } else {
                var previous = input.type;
                input.type = 'text'; input.select();
                try { document.execCommand('copy'); done(); } catch (e) { say('Gagal menyalin; tampilkan lalu salin manual.'); }
                input.type = previous;
            }
        });
    })();
</script>
@endonce
