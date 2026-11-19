@extends('layouts.workspace', ['title' => 'Model AI', 'breadcrumb' => 'Model AI'])

@section('content')
    @php
        $modelName = fn (?string $id) => collect($models)->firstWhere('id', $id)['name'] ?? $id;
        $loggedIn = $status['logged_in'];
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
        <p class="form-alert">{{ session('error') }}</p>
    @endif

    <div class="ap-top">
        <article class="ap-card" id="akun-google">
            <div class="ap-card-head">
                <div>
                    <p class="section-kicker">AKUN GOOGLE</p>
                    <h2>Antigravity</h2>
                </div>
                <span class="ap-model-pill {{ $loggedIn ? '' : 'is-empty' }}">{{ $loggedIn ? 'Terhubung' : 'Belum terhubung' }}</span>
            </div>

            <div class="ap-health">
                <div class="ap-health-row">
                    <span>Antigravity CLI</span>
                    <span><i class="status-dot {{ $status['installed'] ? 'status-ready' : 'status-idle' }}"></i>
                        {{ $status['installed'] ? 'terpasang'.($status['version'] ? ' · versi '.$status['version'] : '') : 'belum terpasang' }}</span>
                </div>
                <div class="ap-health-row">
                    <span>Login akun Google</span>
                    <span><i class="status-dot {{ $loggedIn ? 'status-ready' : 'status-idle' }}"></i>{{ $loggedIn ? 'aktif' : 'belum login' }}</span>
                </div>
                <div class="ap-health-row">
                    <span>Model yang bisa dipakai</span>
                    <span>{{ $status['model_count'] }}</span>
                </div>
            </div>

            <div class="ap-head-actions" style="margin-top:16px; display:flex; flex-wrap:wrap; gap:8px;">
                <button type="button" class="button button-primary" data-agy-login>{{ $loggedIn ? 'Hubungkan ulang akun Google' : 'Hubungkan akun Google' }}</button>
                @if ($loggedIn)
                    <form method="POST" action="{{ route('ai-model.test') }}">@csrf<button type="submit" class="button button-secondary">Uji koneksi</button></form>
                    <form method="POST" action="{{ route('ai-model.models.refresh') }}">@csrf<button type="submit" class="button button-secondary">Perbarui daftar model</button></form>
                    <button type="button" class="button button-secondary" data-agy-logout>Keluar</button>
                @endif
            </div>

            <div class="ap-login" data-agy-panel hidden style="margin-top:18px; border-top:1px solid var(--line, #e5e7eb); padding-top:16px;">
                <p class="ap-note" data-agy-message>Menyiapkan login…</p>
                <div data-agy-steps hidden>
                    <p class="ap-note"><strong>1.</strong> Buka halaman login Google, masuk dengan akun yang ingin dipakai, lalu salin kode yang muncul.</p>
                    <a class="button button-secondary" data-agy-url href="#" target="_blank" rel="noopener noreferrer">Buka halaman login Google ↗</a>
                    <p class="ap-note" style="margin-top:14px;"><strong>2.</strong> Tempel kodenya di sini.</p>
                    <form class="ap-model-form" data-agy-code-form>
                        <div class="form-field">
                            <label for="agy-code">Kode otorisasi</label>
                            <input id="agy-code" name="code" type="text" autocomplete="off" spellcheck="false" placeholder="4/0AVGzR1A…" required>
                        </div>
                        <button type="submit" class="button button-primary">Hubungkan</button>
                    </form>
                    <p class="form-alert" data-agy-error hidden></p>
                </div>
            </div>

            <p class="form-hint" style="margin-top:14px;">Catatan: menurut ketentuan Google, akun Google pribadi (paket Free/Pro/Ultra) tidak didukung untuk dipakai di dalam aplikasi lain dan berisiko diblokir. Pakai akun Google terpisah, bukan akun utama.</p>
        </article>

        <article class="ap-card">
            <div class="ap-card-head">
                <div>
                    <p class="section-kicker">MODEL PER PERAN</p>
                    <h2>Pilih model agen</h2>
                </div>
                <span class="ap-model-pill {{ $activeModel ? '' : 'is-empty' }}">{{ $activeModel ? $modelName($activeModel) : 'Belum dipilih' }}</span>
            </div>

            @unless ($activeModel)
                <p class="form-alert">Model utama belum dipilih. Semua alur agen menolak berjalan sampai model utama dipilih.</p>
            @endunless
            @if ($errors->any())
                <p class="form-alert">{{ $errors->first() }}</p>
            @endif

            @if ($models === [])
                <p class="form-alert">Daftar model belum terbaca. Hubungkan akun Google dulu, lalu klik Perbarui daftar model.</p>
            @else
                <form method="POST" action="{{ route('ai-model.models.update') }}" class="ap-form-stack">
                    @csrf
                    <div class="form-field">
                        <label for="active_model">Model utama (semua peran)</label>
                        <select id="active_model" name="active_model" required>
                            <option value="" disabled @selected(! $activeModel)>Pilih model</option>
                            @foreach ($models as $model)
                                <option value="{{ $model['id'] }}" @selected($model['id'] === $activeModel)>{{ $model['name'] }}</option>
                            @endforeach
                        </select>
                    </div>

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

                    <button type="submit" class="button button-primary">Simpan model</button>
                    <p class="form-hint">Daftar model berasal dari akun Google yang terhubung, jadi hanya model yang benar-benar bisa dipakai yang muncul. Saran: model kuat untuk Scrum Master dan QA, model cepat untuk Backend dan Frontend.</p>
                </form>
            @endif
        </article>
    </div>

    <div class="ap-duo">
        <article class="ap-card" data-quota-card>
            <div class="ap-card-head">
                <div>
                    <p class="section-kicker">KUOTA AKUN GOOGLE</p>
                    <h2>Sisa kuota</h2>
                </div>
                <button type="button" class="button button-secondary ap-small-button" data-quota-refresh>Perbarui</button>
            </div>
            <div data-quota-body>
                <p class="ap-note">{{ $loggedIn ? 'Membaca kuota…' : 'Hubungkan akun Google dulu untuk melihat kuota.' }}</p>
            </div>
            <p class="form-hint" style="margin-top:10px;">Model dalam satu kelompok berbagi batas 5 jam dan batas mingguan. Kuota terpakai sebanding dengan biaya token, jadi tugas pendek dan model yang lebih hemat membuat kuota bertahan lebih lama.</p>
        </article>

        <article class="ap-card" data-server-card>
            <div class="ap-card-head">
                <div>
                    <p class="section-kicker">SERVER</p>
                    <h2>Sumber daya VPS</h2>
                </div>
                <span class="ap-model-pill" data-server-uptime>…</span>
            </div>
            <div data-server-body>
                <p class="ap-note">Membaca pemakaian server…</p>
            </div>
        </article>
    </div>

    <article class="ap-card" style="margin-top:18px;">
        <div class="ap-card-head">
            <div>
                <p class="section-kicker">PEMAKAIAN 30 HARI</p>
                <h2>Token AI</h2>
            </div>
            <span class="ap-model-pill">{{ number_format($usage['grand_total']) }} token</span>
        </div>
        @if ($usage['models'] === [])
            <p class="ap-note">Belum ada pemakaian tercatat.</p>
        @else
            <div class="ap-health">
                @foreach (array_slice($usage['models'], 0, 8) as $row)
                    <div class="ap-health-row">
                        <span>{{ $modelName($row['model']) }} <small class="form-hint">({{ $row['provider'] }})</small></span>
                        <span>{{ number_format($row['total']) }} token · {{ number_format($row['calls']) }} panggilan</span>
                    </div>
                @endforeach
            </div>
        @endif
    </article>

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
                if (!response.ok) { say('Login tidak bisa dimulai. Coba lagi sebentar.'); return; }
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
                    if (!response.ok) { say('Keluar tidak bisa dimulai. Coba lagi sebentar.'); return; }
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
            const escapeHtml = (text) => String(text).replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
            const bytes = (value) => {
                const units = ['B', 'KB', 'MB', 'GB', 'TB'];
                let i = 0;
                while (value >= 1024 && i < units.length - 1) { value /= 1024; i++; }
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
            const row = (label, value, percent, level, note) => `
                <div class="ap-quota-row ${level}">
                    <div class="ap-quota-line"><span>${escapeHtml(label)}</span><strong>${escapeHtml(value)}</strong></div>
                    <div class="ap-quota-bar" role="progressbar" aria-label="${escapeHtml(label)}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${Math.round(percent)}"><span style="width:${Math.max(0, Math.min(100, percent))}%"></span></div>
                    ${note ? `<p class="ap-quota-reset">${escapeHtml(note)}</p>` : ''}
                </div>`;
            const usageLevel = (percent) => percent >= 92 ? 'is-empty' : (percent >= 80 ? 'is-low' : '');
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
                        quotaBody.innerHTML = `<p class="form-alert">${escapeHtml(quotaErrors[data.error] || ('Kuota tidak terbaca (' + (data.error || 'kosong') + ').'))}</p>`;
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
                                const level = percent <= 5 ? 'is-empty' : (percent <= 25 ? 'is-low' : '');
                                return row(windows[bucket.window] || bucket.window, 'sisa ' + (percent >= 99.5 || percent < 1 ? Math.round(percent) : percent.toFixed(1)) + '%', percent, level, note);
                            }).join('')}
                        </div>`).join('') + `<p class="ap-quota-reset" style="margin-top:6px;">Dibaca ${new Date(data.fetched_at).toLocaleTimeString('id-ID', {hour: '2-digit', minute: '2-digit'})}</p>`;
                } catch (error) {
                    quotaBody.innerHTML = '<p class="form-alert">Kuota tidak bisa dibaca sekarang.</p>';
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
                        rows.push(row('Network masuk (' + net.interface + ')', rate(net.rx_rate), net.rx_rate / netPeak * 100, '', 'Total sejak boot: ' + bytes(net.rx_total)));
                        rows.push(row('Network keluar (' + net.interface + ')', rate(net.tx_rate), net.tx_rate / netPeak * 100, '', 'Total sejak boot: ' + bytes(net.tx_total) + ' · bar relatif terhadap puncak sejak halaman dibuka'));
                    }
                    serverBody.innerHTML = rows.join('');
                    uptimePill.textContent = data.uptime ? 'Nyala ' + duration(data.uptime) : 'Server';
                } catch (error) {
                    serverBody.innerHTML = '<p class="form-alert">Pemakaian server tidak bisa dibaca sekarang.</p>';
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
