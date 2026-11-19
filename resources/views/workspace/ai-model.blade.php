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
