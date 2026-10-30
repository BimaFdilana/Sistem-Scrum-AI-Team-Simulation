@extends('layouts.workspace', ['title' => 'AI Providers', 'breadcrumb' => 'AI Providers'])

@section('content')
    @php
        $apiKeyTabDefault = (bool) (session('api_keys_tab') || $errors->any());
        $apiKeyCount = 0;
        if (is_array($apiKeys)) {
            foreach ($apiKeys as $groups) {
                $apiKeyCount += is_array($groups) ? count($groups) : 0;
            }
        }
    @endphp
    <style>
        .provider-tabs{display:inline-flex;gap:4px;padding:4px;margin:0 0 18px;border:1px solid var(--line);border-radius:999px;background:var(--panel)}
        .provider-tab{min-height:34px;padding:0 18px;border:0;border-radius:999px;background:transparent;color:var(--muted);font-size:12px;font-weight:600;cursor:pointer}
        .provider-tab:hover{color:var(--text)}
        .provider-tab.is-active{background:rgba(var(--accent-rgb),.14);color:var(--accent-strong)}
        .provider-tab .tab-count{display:inline-flex;align-items:center;min-width:20px;height:20px;padding:0 6px;margin-left:7px;border:1px solid var(--line);border-radius:999px;color:var(--muted);font-size:10px}
        .provider-tab.is-active .tab-count{border-color:rgba(var(--accent-rgb),.35);color:var(--accent-strong)}
        .tab-panel{display:none}
        .tab-panel.is-active{display:block}
        .api-key-group{grid-template-columns:minmax(0,1fr) auto}
    </style>
    <section class="page-heading">
        <div>
            <p class="eyebrow"><span class="eyebrow-line"></span>AI PROVIDERS</p>
            <h1>Provider AI</h1>
            <p class="page-subtitle">Hubungkan akun langganan AI kamu (Google, ChatGPT, Claude) lewat login OAuth di tab Provider, atau tambahkan API key (multi key &amp; multi akun) lewat tab API Key. Key disimpan di konfigurasi gateway CLIProxyAPI, bukan di database website.</p>
        </div>
        <span class="count-pill">{{ count($providers) }} providers</span>
    </section>

    @if (session('success'))
        <p class="success-banner">{{ session('success') }}</p>
    @endif
    @if (session('error'))
        <p class="form-alert">{{ session('error') }}</p>
    @endif

    <section class="page-heading" style="margin-top: 0;">
        <div>
            <p class="page-subtitle" style="margin: 0;">
                Gateway: {!! $gatewayUp ? '<strong>jalan</strong>' : '<strong>tidak jalan</strong>' !!}
                &nbsp;·&nbsp;
                Management API: {!! $managementUp ? '<strong>aktif</strong>' : '<strong>nonaktif</strong>' !!}
            </p>
            @unless ($gatewayUp && $managementUp)
                <p class="page-subtitle" style="margin: 0.25rem 0 0;">Pastikan service CLIProxyAPI berjalan di server dan <code>CLIPROXY_MANAGEMENT_KEY</code> terisi di <code>.env</code>.</p>
            @endunless
        </div>
    </section>

    <p class="page-subtitle">Pemakaian token 30 hari terakhir: <strong>{{ number_format($usage['grand_total']) }} token</strong>. Bar di tiap kartu menunjukkan proporsi pemakaian antar provider dari pencatatan ScrumSpace sendiri (bukan persentase terhadap limit langgananmu), karena gateway tidak menyediakan angka limit resmi.</p>

    <nav class="provider-tabs" role="tablist" aria-label="Jenis provider">
        <button type="button" class="provider-tab{{ $apiKeyTabDefault ? '' : ' is-active' }}" role="tab" id="tab-btn-providers" aria-selected="{{ $apiKeyTabDefault ? 'false' : 'true' }}" aria-controls="tab-panel-providers" data-tab-target="providers">Provider</button>
        <button type="button" class="provider-tab{{ $apiKeyTabDefault ? ' is-active' : '' }}" role="tab" id="tab-btn-api-keys" aria-selected="{{ $apiKeyTabDefault ? 'true' : 'false' }}" aria-controls="tab-panel-api-keys" data-tab-target="api-keys">API Key @if ($apiKeyCount > 0)<span class="tab-count">{{ $apiKeyCount }}</span>@endif</button>
    </nav>

    <div id="tab-panel-providers" class="tab-panel{{ $apiKeyTabDefault ? '' : ' is-active' }}" role="tabpanel" aria-labelledby="tab-btn-providers">
        <section class="agent-directory-list">
            @foreach ($providers as $slug => $info)
                @php
                    $connected = $info['connected'];
                    $cardUsage = $usage['by_slug'][$slug] ?? null;
                    $pct = $cardUsage && $usage['grand_total'] > 0 ? round($cardUsage['total'] / $usage['grand_total'] * 100) : 0;
                @endphp
                <article class="directory-row">
                    <span class="agent-avatar avatar-{{ $slug === 'antigravity' ? 'mint' : ($slug === 'codex' ? 'violet' : 'amber') }}">{{ mb_strtoupper(mb_substr($info['label'], 0, 1)) }}</span>
                    <div class="directory-main">
                        <h2>{{ $info['label'] }}</h2>
                        <p>{{ $info['description'] }}</p>
                        @if ($connected && $info['email'])
                            <p><small>{{ $info['email'] }}</small></p>
                        @endif
                        @if ($cardUsage)
                            <div style="margin-top:.75rem">
                                <div style="display:flex;justify-content:space-between;gap:1rem"><strong>Pemakaian 30 hari: {{ number_format($cardUsage['total']) }} token · {{ $cardUsage['calls'] }} panggilan · {{ $pct }}%</strong></div>
                                <div style="background:#e5e7eb;border-radius:999px;height:12px;overflow:hidden;margin-top:.25rem"><div style="background:#2563eb;height:12px;width:{{ $pct }}%"></div></div>
                                <ul style="margin:.5rem 0 0;padding-left:1.1rem">
                                    @foreach ($cardUsage['models'] as $model)
                                        <li><small>{{ $model['model'] }} — {{ number_format($model['total']) }} token · {{ $model['calls'] }} panggilan</small></li>
                                    @endforeach
                                </ul>
                            </div>
                        @else
                            <p><small>Belum ada pemakaian tercatat (30 hari terakhir).</small></p>
                        @endif
                    </div>
                    <div class="directory-role"><span>{{ implode(', ', $info['models']) }}</span></div>
                    <span class="agent-status"><i></i>{{ $connected ? 'Terhubung' : 'Belum terhubung' }}</span>
                    <div class="directory-actions">
                        @if ($connected)
                            <form method="POST" action="{{ route('ai-providers.disconnect', ['provider' => $slug]) }}">
                                @csrf
                                <button class="button" type="submit" onclick="return handleConfirm(event, {title: 'Putus akun?', message: 'Putus akun {{ $info['label'] }}? Kamu bisa mengaktifkannya lagi kapan pun.', confirmLabel: 'Ya, putuskan', danger: true})">Putuskan</button>
                            </form>
                        @else
                            <a class="button button-primary" href="{{ route('ai-providers.connect', ['provider' => $slug]) }}">Hubungkan <span aria-hidden="true">→</span></a>
                        @endif
                    </div>
                </article>
            @endforeach
        </section>
    </div>

    <div id="tab-panel-api-keys" class="tab-panel{{ $apiKeyTabDefault ? ' is-active' : '' }}" role="tabpanel" aria-labelledby="tab-btn-api-keys">
        <section class="form-panel">
            <div class="panel-heading"><div><p class="section-kicker">API KEY</p><h2>Akun API key (multi key &amp; multi akun)</h2></div></div>
            <p class="page-subtitle" style="margin-top:0">Satu provider bisa punya beberapa akun (grup), dan satu akun bisa punya beberapa key. Gateway merotasi key di dalam satu akun dan berpindah akun saat satu akun kena limit. Key tidak pernah ditampilkan utuh di sini — hanya 4 karakter awal dan akhir.</p>
            @if ($errors->any())
                <div class="form-alert"><ul style="margin:0;padding-left:1.1rem">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
            @endif
            @if ($apiKeys === null || ! $v8Up)
                <p class="form-alert">Management API v8 gateway tidak aktif / tidak dapat dihubungi — pastikan gateway versi 8.x berjalan dan <code>CLIPROXY_MANAGEMENT_KEY</code> terisi di <code>.env</code>. Setelah aktif, API key bisa dikelola dari sini.</p>
            @else
                @forelse ($apiKeys as $provider => $groups)
                    <h3 style="margin-top:1.25rem">{{ $apiKeyProviders[$provider] ?? $provider }}</h3>
                    @foreach ($groups as $group)
                        <article class="directory-row api-key-group">
                            <div class="directory-main">
                                <h2>{{ $group['name'] }}</h2>
                                @if ($group['base_url'])<p><small>Endpoint: {{ $group['base_url'] }}</small></p>@endif
                                @if ($group['models'])<p><small>Model: {{ implode(', ', $group['models']) }}</small></p>@endif
                                <ul style="margin:.5rem 0 0;padding-left:1.1rem">
                                    @foreach ($group['keys'] as $index => $masked)
                                        <li><small><code>{{ $masked }}</code></small>
                                            <form method="POST" action="{{ route('ai-providers.api-keys.keys.destroy', ['provider' => $provider, 'group' => $group['name'], 'index' => $index]) }}" style="display:inline">
                                                @csrf @method('DELETE')
                                                <button class="button" type="submit" onclick="return handleConfirm(event, {title: 'Hapus key?', message: 'Hapus key {{ $masked }} dari akun {{ $group['name'] }}?{{ count($group['keys']) === 1 ? ' Ini key terakhir — akunnya ikut terhapus.' : '' }}', confirmLabel: 'Ya, hapus', danger: true})">Hapus key</button>
                                            </form>
                                        </li>
                                    @endforeach
                                </ul>
                                <form method="POST" action="{{ route('ai-providers.api-keys.keys.store', ['provider' => $provider, 'group' => $group['name']]) }}" style="margin-top:.75rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:end">
                                    @csrf
                                    <div class="form-field" style="margin:0">
                                        <label for="key-{{ $provider }}-{{ $group['name'] }}">Tambah key ke akun ini</label>
                                        <input type="password" id="key-{{ $provider }}-{{ $group['name'] }}" name="api_key" autocomplete="off" spellcheck="false" required minlength="8" maxlength="500">
                                    </div>
                                    <button class="button button-primary" type="submit">Tambah key</button>
                                </form>
                                <form method="POST" action="{{ route('ai-providers.api-keys.models.update', ['provider' => $provider, 'group' => $group['name']]) }}" style="margin-top:.75rem">
                                    @csrf
                                    <div class="form-field" style="margin:0">
                                        <label for="models-{{ $provider }}-{{ $group['name'] }}">Ubah daftar model</label>
                                        <textarea id="models-{{ $provider }}-{{ $group['name'] }}" name="models_text" rows="4" spellcheck="false">{{ implode("\n", array_map(fn ($m) => $m['name'].(isset($m['alias']) ? '|'.$m['alias'] : ''), $group['models_raw'] ?? [])) }}</textarea>
                                    </div>
                                    <button class="button" type="submit" style="margin-top:.5rem">Simpan daftar model</button>
                                </form>
                            </div>
                            <div class="directory-actions">
                                <form method="POST" action="{{ route('ai-providers.api-keys.destroy', ['provider' => $provider, 'group' => $group['name']]) }}">
                                    @csrf @method('DELETE')
                                    <button class="button" type="submit" onclick="return handleConfirm(event, {title: 'Hapus akun?', message: 'Hapus akun {{ $group['name'] }} beserta seluruh key-nya dari gateway?', confirmLabel: 'Ya, hapus', danger: true})">Hapus akun</button>
                                </form>
                            </div>
                        </article>
                    @endforeach
                @empty
                    <p><small>Belum ada akun API key.</small></p>
                @endforelse
                <h3 style="margin-top:1.5rem">Tambah akun API key</h3>
                <form method="POST" action="{{ route('ai-providers.api-keys.store') }}">
                    @csrf
                    <div class="form-field">
                        <label for="provider">Provider</label>
                        <select id="provider" name="provider" required>
                            @foreach ($apiKeyProviders as $slug => $label)<option value="{{ $slug }}" @selected(old('provider') === $slug)>{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <div class="form-field">
                        <label for="group">Nama akun</label>
                        <input type="text" id="group" name="group" value="{{ old('group') }}" required maxlength="64" spellcheck="false" placeholder="mis. gemini-utama">
                        <p class="form-hint">Huruf kecil, angka, dash, underscore. Harus unik per provider.</p>
                    </div>
                    <div class="form-field">
                        <label for="api_key">API key</label>
                        <input type="password" id="api_key" name="api_key" autocomplete="off" spellcheck="false" required minlength="8" maxlength="500">
                        <p class="form-hint">Key dikirim ke gateway dan disimpan di konfigurasinya. Tidak pernah ditampilkan lagi setelah disimpan.</p>
                    </div>
                    <div class="form-field">
                        <label for="base_url">Endpoint (base URL)</label>
                        <input type="url" id="base_url" name="base_url" value="{{ old('base_url') }}" maxlength="500" spellcheck="false" placeholder="kosongkan untuk endpoint bawaan provider">
                        <p class="form-hint">Wajib untuk OpenAI-compatible (custom). Untuk provider native, isi hanya bila memakai endpoint lain (mis. Vertex-compatible).</p>
                    </div>
                    <div class="form-field">
                        <label for="models_text">Daftar model</label>
                        <textarea id="models_text" name="models_text" rows="6" spellcheck="false" placeholder="mimo-v2.6-pro&#10;kimi-k3&#10;deepseek-v4-pro">{{ old('models_text') }}</textarea>
                        <p class="form-hint">Satu model per baris. Wajib untuk OpenAI-compatible (custom); opsional untuk provider native. Tambah <code>|alias</code> di akhir baris untuk nama tampil, mis. <code>kimi-k3|Kimi K3</code>. Daftar ini yang diteruskan gateway ke endpoint akun — satu key bisa melayani banyak model.</p>
                    </div>
                    <div class="form-actions">
                        <button class="button button-primary" type="submit">Tambah akun</button>
                    </div>
                </form>
            @endif
        </section>

        @if ($usage['other'])
            <section class="empty-panel" style="margin-top:14px">
                <div class="panel-heading"><div><p class="section-kicker">PEMAKAIAN LAIN</p><h2>Pemakaian provider tanpa kartu di atas</h2></div></div>
                <p style="margin-top:0">Pemakaian dari provider API-key atau label yang belum punya kartu OAuth tetap ditampilkan di sini agar tidak hilang.</p>
                @foreach ($usage['other'] as $row)
                    @php $pct = $usage['grand_total'] > 0 ? round($row['total'] / $usage['grand_total'] * 100) : 0; @endphp
                    <div style="margin-bottom:.75rem">
                        <div style="display:flex;justify-content:space-between;gap:1rem"><strong>{{ $row['provider'] }}</strong><span>{{ number_format($row['total']) }} token · {{ $row['calls'] }} panggilan · {{ $pct }}%</span></div>
                        <div style="background:#e5e7eb;border-radius:999px;height:12px;overflow:hidden;margin-top:.25rem"><div style="background:#2563eb;height:12px;width:{{ $pct }}%"></div></div>
                    </div>
                @endforeach
            </section>
        @endif
    </div>

    <script>
        (function () {
            var buttons = document.querySelectorAll('[data-tab-target]');
            function switchTab(target) {
                buttons.forEach(function (btn) {
                    var active = btn.getAttribute('data-tab-target') === target;
                    btn.classList.toggle('is-active', active);
                    btn.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                document.querySelectorAll('.tab-panel').forEach(function (panel) {
                    panel.classList.toggle('is-active', panel.id === 'tab-panel-' + target);
                });
            }
            buttons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    switchTab(btn.getAttribute('data-tab-target'));
                });
            });
        })();
    </script>
@endsection
