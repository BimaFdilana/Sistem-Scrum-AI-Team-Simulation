@extends('layouts.workspace', ['title' => 'Model AI', 'breadcrumb' => 'Model AI'])

@section('content')
    @php
        $modelName = fn (?string $id) => collect($models)->firstWhere('id', $id)['name'] ?? $id;
        $loggedIn = $status['logged_in'];
        $customRoles = collect($roleModels)->filter()->count();
        $usageMax = max(1, (int) collect($usage['models'])->max('total'));
    @endphp

    <section class="page-heading">
        <div>
            <p class="eyebrow"><span class="eyebrow-line"></span>MODEL AI</p>
            <h1>Model AI</h1>
            <p class="page-subtitle">Semua agen (Scrum Master, Backend, Frontend, QA) bekerja lewat Antigravity CLI dengan akun Google yang terhubung di sini. Pilih model utama, dan bila perlu model khusus untuk tiap peran.</p>
        </div>
        <span class="count-pill">{{ $loggedIn ? $status['model_count'].' model tersedia' : 'Belum terhubung' }}</span>
    </section>

    @if (session('status'))
        <p class="success-banner">{{ session('status') }}</p>
    @endif
    @if (session('error'))
        <p class="form-alert am-flash">{{ session('error') }}</p>
    @endif

    {{-- Ringkasan ------------------------------------------------------- --}}
    <section class="am-summary" aria-label="Ringkasan">
        <div class="am-tile">
            <span class="am-tile-label">Akun Google</span>
            <strong class="am-tile-value"><i class="status-dot {{ $loggedIn ? 'status-ready' : 'status-idle' }}"></i>{{ $loggedIn ? 'Terhubung' : 'Belum terhubung' }}</strong>
            <span class="am-tile-meta">{{ $status['installed'] ? 'Antigravity '.($status['version'] ? 'v'.$status['version'] : 'terpasang') : 'Antigravity belum terpasang' }}</span>
        </div>
        <div class="am-tile">
            <span class="am-tile-label">Model utama</span>
            <strong class="am-tile-value {{ $activeModel ? '' : 'is-warning' }}">{{ $activeModel ? $modelName($activeModel) : 'Belum dipilih' }}</strong>
            <span class="am-tile-meta">{{ $customRoles > 0 ? $customRoles.' peran memakai model khusus' : 'Dipakai semua peran' }}</span>
        </div>
        <div class="am-tile">
            <span class="am-tile-label">Kuota Gemini · 5 jam</span>
            <strong class="am-tile-value" data-summary-quota>{{ $loggedIn ? '…' : '-' }}</strong>
            <span class="am-tile-meta" data-summary-quota-meta>{{ $loggedIn ? 'Membaca kuota' : 'Hubungkan akun dulu' }}</span>
        </div>
        <div class="am-tile">
            <span class="am-tile-label">Server</span>
            <strong class="am-tile-value" data-summary-server>…</strong>
            <span class="am-tile-meta" data-summary-server-meta>Membaca pemakaian</span>
        </div>
    </section>

    <div class="am-layout">
        {{-- Kolom utama: pengaturan model dan pemakaian token --------------- --}}
        <div class="am-column">
            <article class="ap-card am-card">
                <header class="am-card-head">
                    <div>
                        <p class="section-kicker">MODEL PER PERAN</p>
                        <h2>Pilih model agen</h2>
                    </div>
                    <span class="ap-model-pill {{ $activeModel ? '' : 'is-empty' }}">{{ $activeModel ? $modelName($activeModel) : 'Belum dipilih' }}</span>
                </header>

                @unless ($activeModel)
                    <p class="form-alert am-alert">Model utama belum dipilih. Semua alur agen menolak berjalan sampai model utama dipilih.</p>
                @endunless
                @if ($errors->any())
                    <p class="form-alert am-alert">{{ $errors->first() }}</p>
                @endif

                @if ($models === [])
                    <div class="am-empty">
                        <strong>Daftar model belum terbaca</strong>
                        <span>Hubungkan akun Google dulu, lalu klik Perbarui daftar model.</span>
                    </div>
                @else
                    <form method="POST" action="{{ route('ai-model.models.update') }}" class="am-model-form">
                        @csrf
                        <div class="form-field am-main-model">
                            <label for="active_model">Model utama <small>dipakai semua peran</small></label>
                            <select id="active_model" name="active_model" required>
                                <option value="" disabled @selected(! $activeModel)>Pilih model</option>
                                @foreach ($models as $model)
                                    <option value="{{ $model['id'] }}" @selected($model['id'] === $activeModel)>{{ $model['name'] }}</option>
                                @endforeach
                            </select>
                        </div>

                        <p class="am-subhead">Model khusus per peran <small>opsional</small></p>
                        <div class="am-role-grid">
                            @foreach ($roles as $slug => $roleName)
                                <div class="form-field">
                                    <label for="role-{{ $slug }}">{{ $roleName }}</label>
                                    <select id="role-{{ $slug }}" name="role_models[{{ $slug }}]">
                                        <option value="">Ikuti model utama</option>
                                        @foreach ($models as $model)
                                            <option value="{{ $model['id'] }}" @selected($model['id'] === ($roleModels[$slug] ?? null))>{{ $model['name'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endforeach
                        </div>

                        <footer class="am-card-foot">
                            <p class="form-hint">Hanya model dari akun Google yang terhubung yang muncul. Saran: model kuat untuk Scrum Master dan QA, model cepat untuk Backend dan Frontend.</p>
                            <button type="submit" class="button button-primary">Simpan model</button>
                        </footer>
                    </form>
                @endif
            </article>

            <article class="ap-card am-card">
                <header class="am-card-head">
                    <div>
                        <p class="section-kicker">PEMAKAIAN 30 HARI</p>
                        <h2>Token AI</h2>
                    </div>
                    <span class="ap-model-pill">{{ number_format($usage['grand_total']) }} token</span>
                </header>
                @if ($usage['models'] === [])
                    <div class="am-empty"><span>Belum ada pemakaian tercatat.</span></div>
                @else
                    <div class="am-meter-list">
                        @foreach (array_slice($usage['models'], 0, 8) as $row)
                            <div class="ap-quota-row">
                                <div class="ap-quota-line">
                                    <span>{{ $modelName($row['model']) }} <small class="form-hint">{{ $row['provider'] }}</small></span>
                                    <strong>{{ number_format($row['total']) }}</strong>
                                </div>
                                <div class="ap-quota-bar"><span style="width: {{ round($row['total'] / $usageMax * 100, 1) }}%"></span></div>
                                <p class="ap-quota-reset">{{ number_format($row['calls']) }} panggilan</p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </article>
        </div>

        {{-- Kolom samping: akun, kuota, server ------------------------------- --}}
        <aside class="am-column">
            <article class="ap-card am-card" id="akun-google">
                <header class="am-card-head">
                    <div>
                        <p class="section-kicker">AKUN GOOGLE</p>
                        <h2>Antigravity</h2>
                    </div>
                    <span class="ap-model-pill {{ $loggedIn ? '' : 'is-empty' }}">{{ $loggedIn ? 'Terhubung' : 'Belum terhubung' }}</span>
                </header>

                <dl class="am-facts">
                    <div><dt>Antigravity CLI</dt><dd><i class="status-dot {{ $status['installed'] ? 'status-ready' : 'status-idle' }}"></i>{{ $status['installed'] ? ($status['version'] ? 'versi '.$status['version'] : 'terpasang') : 'belum terpasang' }}</dd></div>
                    <div><dt>Login akun Google</dt><dd><i class="status-dot {{ $loggedIn ? 'status-ready' : 'status-idle' }}"></i>{{ $loggedIn ? 'aktif' : 'belum login' }}</dd></div>
                    <div><dt>Model tersedia</dt><dd>{{ $status['model_count'] }}</dd></div>
                </dl>

                <div class="am-actions">
                    <button type="button" class="button button-primary am-action-main" data-agy-login>{{ $loggedIn ? 'Hubungkan ulang akun Google' : 'Hubungkan akun Google' }}</button>
                    @if ($loggedIn)
                        <form method="POST" action="{{ route('ai-model.test') }}">@csrf<button type="submit" class="button button-secondary">Uji koneksi</button></form>
                        <form method="POST" action="{{ route('ai-model.models.refresh') }}">@csrf<button type="submit" class="button button-secondary">Perbarui model</button></form>
                        <button type="button" class="button button-secondary" data-agy-logout>Keluar</button>
                    @endif
                </div>

                <div class="am-login" data-agy-panel hidden>
                    <p class="ap-note" data-agy-message>Menyiapkan login…</p>
                    <div data-agy-steps hidden>
                        <ol class="am-steps">
                            <li>
                                <span>Buka halaman login Google, masuk dengan akun yang ingin dipakai, lalu salin kode yang muncul.</span>
                                <a class="button button-secondary" data-agy-url href="#" target="_blank" rel="noopener noreferrer">Buka halaman login Google ↗</a>
                            </li>
                            <li>
                                <span>Tempel kodenya di sini.</span>
                                <form class="ap-model-form" data-agy-code-form>
                                    <div class="form-field">
                                        <label for="agy-code">Kode otorisasi</label>
                                        <input id="agy-code" name="code" type="text" autocomplete="off" spellcheck="false" placeholder="4/0AVGzR1A…" required>
                                    </div>
                                    <button type="submit" class="button button-primary">Hubungkan</button>
                                </form>
                            </li>
                        </ol>
                        <p class="form-alert am-alert" data-agy-error hidden></p>
                    </div>
                </div>

                <p class="am-callout">Menurut ketentuan Google, akun pribadi (Free/Pro/Ultra) tidak didukung untuk dipakai di dalam aplikasi lain dan berisiko diblokir. Pakai akun Google terpisah, bukan akun utama.</p>
            </article>

            <article class="ap-card am-card" data-quota-card>
                <header class="am-card-head">
                    <div>
                        <p class="section-kicker">KUOTA AKUN GOOGLE</p>
                        <h2>Sisa kuota</h2>
                    </div>
                    <button type="button" class="button button-secondary am-small-button" data-quota-refresh @disabled(! $loggedIn)>Perbarui</button>
                </header>
                <div data-quota-body>
                    <p class="ap-note">{{ $loggedIn ? 'Membaca kuota…' : 'Hubungkan akun Google dulu untuk melihat kuota.' }}</p>
                </div>
                <p class="form-hint am-card-note">Model dalam satu kelompok berbagi batas 5 jam dan batas mingguan. Tugas pendek dan model yang lebih hemat membuat kuota bertahan lebih lama.</p>
            </article>

            <article class="ap-card am-card" data-server-card>
                <header class="am-card-head">
                    <div>
                        <p class="section-kicker">SERVER</p>
                        <h2>Sumber daya VPS</h2>
                    </div>
                    <span class="ap-model-pill" data-server-uptime>…</span>
                </header>
                <div data-server-body>
                    <p class="ap-note">Membaca pemakaian server…</p>
                </div>
            </article>
        </aside>
    </div>

    <script>
        (() => {
            const token = document.querySelector('meta[name="csrf-token"]').content;
            const panel = document.querySelector('[data-agy-panel]');
            const message = document.querySelector('[data-agy-message]');
            const steps = document.querySelector('[data-agy-steps]');
            const urlLink = document.querySelector('[data-agy-url]');
            const codeForm = document.querySelector('[data-agy-code-form]');
            const errorBox = document.querySelector('[data-agy-error]');
            const sessionUrl = @json(route('ai-model.session', ['session' => '__ID__']));
            const codeUrl = @json(route('ai-model.session.code', ['session' => '__ID__']));
            let session = null;
            let timer = null;

            const post = (url, body) => fetch(url, {
                method: 'POST',
                headers: {'X-CSRF-TOKEN': token, 'Accept': 'application/json', 'Content-Type': 'application/json'},
                body: JSON.stringify(body || {}),
            });
            const say = (text) => { message.textContent = text; };
            const showError = (text) => { errorBox.textContent = text; errorBox.hidden = !text; };
            const stop = () => { clearInterval(timer); timer = null; };

            const poll = (kind) => {
                stop();
                timer = setInterval(async () => {
                    const response = await fetch(sessionUrl.replace('__ID__', session), {headers: {'Accept': 'application/json'}});
                    const state = await response.json();
                    if (state.status === 'waiting_code' && state.url) {
                        say('Login Google siap.');
                        urlLink.href = state.url;
                        steps.hidden = false;
                    } else if (state.status === 'submitting') {
                        say('Memeriksa kode…');
                    } else if (state.status === 'submitted' || state.status === 'already_logged_in') {
                        stop();
                        if (kind === 'logout') {
                            say(state.logged_in ? 'Keluar belum berhasil. Coba lagi.' : 'Akun Google sudah dikeluarkan. Memuat ulang…');
                        } else {
                            say(state.logged_in ? 'Akun Google terhubung. Memuat ulang…' : 'Login belum berhasil. Mulai ulang dan salin kode terbaru.');
                        }
                        if ((kind === 'logout') !== Boolean(state.logged_in)) {
                            setTimeout(() => window.location.reload(), 1200);
                        }
                    } else if (state.status === 'error' || state.status === 'timeout' || state.status === 'missing') {
                        stop();
                        say(state.message || 'Proses berhenti. Coba lagi.');
                    }
                }, 2000);
            };

            document.querySelector('[data-agy-login]').addEventListener('click', async () => {
                panel.hidden = false;
                steps.hidden = true;
                showError('');
                say('Menyiapkan login Google…');
                const response = await post(@json(route('ai-model.login')));
                if (!response.ok) { say(response.status === 429 ? 'Terlalu sering dicoba. Tunggu satu menit lalu coba lagi.' : 'Login tidak bisa dimulai. Coba lagi sebentar.'); return; }
                session = (await response.json()).session;
                poll('login');
            });

            const logoutButton = document.querySelector('[data-agy-logout]');
            if (logoutButton) {
                logoutButton.addEventListener('click', async () => {
                    if (!window.confirm('Keluarkan akun Google? Semua agen berhenti sampai akun dihubungkan lagi.')) { return; }
                    panel.hidden = false;
                    steps.hidden = true;
                    say('Mengeluarkan akun…');
                    const response = await post(@json(route('ai-model.logout')));
                    if (!response.ok) { say(response.status === 429 ? 'Terlalu sering dicoba. Tunggu satu menit lalu klik Keluar lagi.' : 'Keluar tidak bisa dimulai. Coba lagi sebentar.'); return; }
                    session = (await response.json()).session;
                    poll('logout');
                });
            }

            // Kuota akun dan pemakaian server ---------------------------------
            const loggedIn = @json($loggedIn);
            const quotaUrl = @json(route('ai-model.quota'));
            const serverUrl = @json(route('ai-model.server'));
            const quotaBody = document.querySelector('[data-quota-body]');
            const serverBody = document.querySelector('[data-server-body]');
            const uptimePill = document.querySelector('[data-server-uptime]');
            const summary = (name, value, meta, level) => {
                const valueEl = document.querySelector(`[data-summary-${name}]`);
                valueEl.textContent = value;
                valueEl.classList.toggle('is-warning', level === 'is-low');
                valueEl.classList.toggle('is-danger', level === 'is-empty');
                document.querySelector(`[data-summary-${name}-meta]`).textContent = meta;
            };
            const escapeHtml = (text) => String(text).replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
            const bytes = (value) => {
                const units = ['B', 'KB', 'MB', 'GB', 'TB'];
                let i = 0;
                while (value >= 1000 && i < units.length - 1) { value /= 1024; i++; }
                return (value >= 100 || i === 0 ? Math.round(value) : value.toFixed(1)) + ' ' + units[i];
            };
            const rate = (value) => {
                const bits = value * 8;
                if (bits >= 1e6) { return (bits / 1e6).toFixed(1) + ' Mbit/s'; }
                if (bits >= 1e3) { return (bits / 1e3).toFixed(1) + ' kbit/s'; }
                return Math.round(bits) + ' bit/s';
            };
            const duration = (seconds) => {
                const days = Math.floor(seconds / 86400);
                const hours = Math.floor((seconds % 86400) / 3600);
                const minutes = Math.floor((seconds % 3600) / 60);
                if (days > 0) { return days + ' hari ' + hours + ' jam'; }
                if (hours > 0) { return hours + ' jam ' + minutes + ' mnt'; }
                return Math.max(minutes, 1) + ' mnt';
            };
            const percentText = (percent) => (percent >= 99.5 || percent < 1 ? Math.round(percent) : percent.toFixed(1)) + '%';
            const row = (label, value, percent, level, note) => `
                <div class="ap-quota-row ${level}">
                    <div class="ap-quota-line"><span>${escapeHtml(label)}</span><strong>${escapeHtml(value)}</strong></div>
                    <div class="ap-quota-bar" role="progressbar" aria-label="${escapeHtml(label)}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${Math.round(percent)}"><span style="width:${Math.max(0, Math.min(100, percent))}%"></span></div>
                    ${note ? `<p class="ap-quota-reset">${escapeHtml(note)}</p>` : ''}
                </div>`;
            const usageLevel = (percent) => percent >= 92 ? 'is-empty' : (percent >= 80 ? 'is-low' : '');
            const quotaLevel = (percent) => percent <= 5 ? 'is-empty' : (percent <= 25 ? 'is-low' : '');
            const quotaErrors = {
                NOT_LOGGED_IN: 'Akun Google belum terhubung.',
                AGY_NOT_INSTALLED: 'Antigravity CLI belum terpasang.',
                DISABLED: 'Antigravity dimatikan di konfigurasi.',
                TIMEOUT: 'Antigravity tidak menjawab. Coba Perbarui sebentar lagi.',
                QUOTA_UNREADABLE: 'Format kuota dari Antigravity tidak dikenali.',
            };

            const loadQuota = async (fresh) => {
                if (!loggedIn) { return; }
                if (fresh) { quotaBody.innerHTML = '<p class="ap-note">Membaca kuota…</p>'; }
                try {
                    const response = await fetch(quotaUrl + (fresh ? '?fresh=1' : ''), {headers: {'Accept': 'application/json'}});
                    if (!response.ok) { throw new Error(String(response.status)); }
                    const data = await response.json();
                    if (data.error || !data.groups.length) {
                        quotaBody.innerHTML = `<p class="form-alert am-alert">${escapeHtml(quotaErrors[data.error] || ('Kuota tidak terbaca (' + (data.error || 'kosong') + ').'))}</p>`;
                        summary('quota', '-', 'Kuota tidak terbaca', '');
                        return;
                    }
                    const windows = {'5h': 'Batas 5 jam', weekly: 'Batas mingguan'};
                    quotaBody.innerHTML = data.groups.map((group) => `
                        <div class="ap-quota-account">
                            <div class="ap-quota-head"><strong>${escapeHtml(group.name)}</strong>${group.models ? `<small class="form-hint">${escapeHtml(group.models)}</small>` : ''}</div>
                            ${group.buckets.map((bucket) => {
                                const percent = bucket.remaining * 100;
                                const reset = bucket.reset_at ? new Date(bucket.reset_at) : null;
                                const left = reset ? (reset.getTime() - Date.now()) / 1000 : null;
                                const note = reset ? 'Penuh lagi ' + (left > 60 ? 'dalam ' + duration(left) + ' · ' : '') + reset.toLocaleString('id-ID', {weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit'}) : '';
                                return row(windows[bucket.window] || bucket.window, 'sisa ' + percentText(percent), percent, quotaLevel(percent), note);
                            }).join('')}
                        </div>`).join('') + `<p class="ap-quota-reset am-card-note">Dibaca ${new Date(data.fetched_at).toLocaleTimeString('id-ID', {hour: '2-digit', minute: '2-digit'})}</p>`;

                    const gemini = data.groups.find((group) => /gemini/i.test(group.name)) || data.groups[0];
                    const fiveHour = gemini.buckets.find((bucket) => bucket.window === '5h') || gemini.buckets[0];
                    const weekly = gemini.buckets.find((bucket) => bucket.window === 'weekly');
                    summary('quota', 'sisa ' + percentText(fiveHour.remaining * 100), weekly ? 'Mingguan sisa ' + percentText(weekly.remaining * 100) : gemini.name, quotaLevel(fiveHour.remaining * 100));
                } catch (error) {
                    quotaBody.innerHTML = '<p class="form-alert am-alert">Kuota tidak bisa dibaca sekarang.</p>';
                    summary('quota', '-', 'Kuota tidak terbaca', '');
                }
            };

            let netPeak = 1;
            const loadServer = async () => {
                try {
                    const response = await fetch(serverUrl, {headers: {'Accept': 'application/json'}});
                    if (!response.ok) { throw new Error(String(response.status)); }
                    const data = await response.json();
                    const rows = [];
                    if (data.disk) {
                        rows.push(row('Storage', bytes(data.disk.used) + ' / ' + bytes(data.disk.total), data.disk.percent, usageLevel(data.disk.percent), bytes(data.disk.free) + ' kosong · ' + data.disk.percent + '% terpakai'));
                    }
                    if (data.memory) {
                        const swap = data.memory.swap_total > 0 ? ' · swap ' + bytes(data.memory.swap_used) + ' / ' + bytes(data.memory.swap_total) : '';
                        rows.push(row('RAM', bytes(data.memory.used) + ' / ' + bytes(data.memory.total), data.memory.percent, usageLevel(data.memory.percent), bytes(data.memory.available) + ' tersedia' + swap));
                    }
                    const cpu = data.cpu;
                    const load = cpu.load ? cpu.load.map((v) => v.toFixed(2)).join(' · ') : '-';
                    const cpuPercent = cpu.percent ?? (cpu.load ? Math.min(100, cpu.load[0] / cpu.cores * 100) : 0);
                    rows.push(row('CPU', cpu.percent !== null ? cpu.percent.toFixed(1) + '%' : '-', cpuPercent, usageLevel(cpuPercent), 'Load 1/5/15 menit: ' + load + ' · ' + cpu.cores + ' core'));
                    if (data.network) {
                        const net = data.network;
                        netPeak = Math.max(netPeak, net.rx_rate, net.tx_rate);
                        rows.push(row('Network masuk', rate(net.rx_rate), net.rx_rate / netPeak * 100, '', 'Total sejak boot: ' + bytes(net.rx_total)));
                        rows.push(row('Network keluar', rate(net.tx_rate), net.tx_rate / netPeak * 100, '', 'Total sejak boot: ' + bytes(net.tx_total)));
                    }
                    serverBody.innerHTML = rows.join('') + (data.network ? `<p class="form-hint am-card-note">Antarmuka ${escapeHtml(data.network.interface)}. Bar network relatif terhadap puncak sejak halaman dibuka.</p>` : '');
                    uptimePill.textContent = data.uptime ? 'Nyala ' + duration(data.uptime) : 'Server';

                    const worst = Math.max(data.disk ? data.disk.percent : 0, data.memory ? data.memory.percent : 0);
                    summary('server', 'Disk ' + (data.disk ? Math.round(data.disk.percent) + '%' : '-') + ' · RAM ' + (data.memory ? Math.round(data.memory.percent) + '%' : '-'), 'CPU ' + (cpu.percent !== null ? cpu.percent.toFixed(0) + '%' : '-') + (data.network ? ' · ↓ ' + rate(data.network.rx_rate) : ''), usageLevel(worst));
                } catch (error) {
                    serverBody.innerHTML = '<p class="form-alert am-alert">Pemakaian server tidak bisa dibaca sekarang.</p>';
                    summary('server', '-', 'Tidak terbaca', '');
                }
            };

            document.querySelector('[data-quota-refresh]').addEventListener('click', () => loadQuota(true));
            loadQuota(false);
            loadServer();
            setInterval(() => { if (!document.hidden) { loadServer(); } }, 5000);
            setInterval(() => { if (!document.hidden) { loadQuota(false); } }, 60000);

            codeForm.addEventListener('submit', async (event) => {
                event.preventDefault();
                const input = codeForm.querySelector('input[name="code"]');
                const code = input.value.trim();
                if (!code) { showError('Tempel kode dari halaman Google dulu.'); return; }
                showError('');
                const response = await post(codeUrl.replace('__ID__', session), {code});
                const result = await response.json();
                if (!response.ok) { showError(result.message || 'Kode ditolak.'); return; }
                input.value = '';
                say('Memeriksa kode…');
            });
        })();
    </script>
@endsection
