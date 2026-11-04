@extends('layouts.workspace', ['title' => 'AI Providers', 'breadcrumb' => 'AI Providers'])

@section('content')
    @php
        $connectNames = ['antigravity' => 'Google Gemini', 'codex' => 'GPT', 'anthropic' => 'Claude'];
        $toneFor = fn (string $slug) => $slug === 'antigravity' ? 'mint' : ($slug === 'codex' ? 'violet' : 'amber');
        $activeProviders = array_filter($providers, fn ($info) => $info['connected']);
        $hasActiveOAuth = count($activeProviders) > 0;
        $apiKeyAccountCount = 0;
        if (is_array($apiKeys)) {
            foreach ($apiKeys as $groups) {
                if (is_array($groups)) {
                    $apiKeyAccountCount += count($groups);
                }
            }
        }
        $hasApiKeyAccounts = $apiKeyAccountCount > 0;

        // Pemakaian ringkas untuk tab Ringkasan: gabungan kartu OAuth + label lain.
        $usageRows = [];
        foreach ($usage['by_slug'] as $slug => $row) {
            $usageRows[] = ['label' => $providers[$slug]['label'] ?? $slug, 'total' => $row['total'], 'calls' => $row['calls']];
        }
        foreach ($usage['other'] as $row) {
            $usageRows[] = ['label' => $row['provider'], 'total' => $row['total'], 'calls' => $row['calls']];
        }
        usort($usageRows, fn ($a, $b) => $b['total'] <=> $a['total']);

        // Tab awal ditentukan server agar error validasi langsung mendarat di
        // form yang salah. Error API key memaksa tab API Key terbuka.
        $initialTab = 'overview';
        if ($errors->any() && ! $errors->has('active_model')) {
            $initialTab = 'api-key';
        }
        if (session('api_key_dialog')) {
            $initialTab = 'api-key';
        }
    @endphp

    <section class="page-heading">
        <div>
            <p class="eyebrow"><span class="eyebrow-line"></span>AI PROVIDERS</p>
            <h1>Provider AI</h1>
            <p class="page-subtitle">Semua agent memakai satu model dari provider yang aktif di sini. Tambah provider lewat login OAuth (Google Gemini, GPT, Claude) atau API key dengan multi akun &amp; multi key. Key disimpan di konfigurasi gateway CLIProxyAPI, bukan di database website.</p>
        </div>
        <span class="count-pill">{{ count($activeProviders) }} aktif · {{ count($providers) }} provider</span>
    </section>

    @if (session('status') || session('success'))
        <p class="success-banner">{{ session('status') ?? session('success') }}</p>
    @endif
    @if (session('error'))
        <p class="form-alert">{{ session('error') }}</p>
    @endif

    <div class="ap-shell" data-initial-tab="{{ $initialTab }}">
        <nav class="ap-tabs" role="tablist" aria-label="Bagian halaman AI Providers">
            <button class="ap-tab" type="button" role="tab" id="ap-tab-overview" aria-controls="ap-panel-overview" aria-selected="true" data-tab="overview">
                Ringkasan <span class="ap-tab-count">{{ $activeModel ? $activeModel : 'belum dipilih' }}</span>
            </button>
            <button class="ap-tab" type="button" role="tab" id="ap-tab-providers" aria-controls="ap-panel-providers" aria-selected="false" tabindex="-1" data-tab="providers">
                Provider <span class="ap-tab-count">{{ count($activeProviders) }}</span>
            </button>
            <button class="ap-tab" type="button" role="tab" id="ap-tab-api-key" aria-controls="ap-panel-api-key" aria-selected="false" tabindex="-1" data-tab="api-key">
                API Key <span class="ap-tab-count">{{ $apiKeyAccountCount }}</span>
            </button>
        </nav>

        {{-- Tab 1 — Ringkasan: model aktif + status gateway + pemakaian ringkas. --}}
        <section class="ap-panel" id="ap-panel-overview" role="tabpanel" aria-labelledby="ap-tab-overview" data-panel="overview" @if ($initialTab !== 'overview') hidden @endif>
            <div class="ap-top">
                {{-- Satu model untuk semua agent: menggantikan halaman Konfigurasi
                     Agent per peran (dihapus 3 Nov 2026). Disimpan lewat
                     ActiveModelService dan disebar ke profile Hermes. --}}
                <article class="ap-card">
                    <div class="ap-card-head">
                        <div>
                            <p class="section-kicker">MODEL AKTIF</p>
                            <h2>Satu model untuk semua agent</h2>
                        </div>
                        @if ($activeModel)
                            <span class="ap-model-pill">{{ $activeModel }}</span>
                        @else
                            <span class="ap-model-pill is-empty">Belum dipilih</span>
                        @endif
                    </div>
                    <p class="ap-note">Scrum Master, Backend Developer, Frontend Developer, dan QA Engineer memakai model yang sama. Satu pilihan di sini langsung berlaku untuk estimasi, breakdown, eksekusi, dan QA review — termasuk worker kanban.</p>

                    @unless ($activeModel)
                        <p class="form-alert" style="margin-top:14px;">Belum ada model dipilih. Semua alur agent akan menolak berjalan sampai Anda memilih satu model di bawah.</p>
                    @endunless

                    @error('active_model')
                        <p class="form-alert" style="margin-top:14px;">{{ $message }}</p>
                    @enderror

                    @if (count($availableModels) === 0)
                        <p class="form-alert" style="margin-top:14px;">Daftar model belum terbaca. Pastikan gateway CLIProxyAPI berjalan dan minimal satu provider terhubung.</p>
                    @else
                        <form method="POST" action="{{ route('ai-providers.active-model.update') }}">
                            @csrf
                            <div class="ap-model-form">
                                <div class="form-field">
                                    <label for="active_model">Pilih model</label>
                                    <select id="active_model" name="active_model" required>
                                        @foreach ($availableModels as $modelId)
                                            <option value="{{ $modelId }}" @selected($modelId === $activeModel)>{{ $modelId }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <button type="submit" class="button button-primary">Simpan model</button>
                            </div>
                            <p class="form-hint" style="margin-top:8px;">{{ count($availableModels) }} model tersedia dari provider yang terhubung. Daftar berasal langsung dari gateway, jadi hanya model yang benar-benar bisa dipanggil yang muncul.</p>
                        </form>
                    @endif
                </article>

                <article class="ap-card">
                    <div class="ap-card-head">
                        <div>
                            <p class="section-kicker">STATUS</p>
                            <h2>Gateway CLIProxyAPI</h2>
                        </div>
                    </div>
                    <div class="ap-health">
                        <div class="ap-health-row">
                            <span>Gateway</span>
                            <span>
                                <i class="status-dot {{ $gatewayUp ? 'status-ready' : 'status-idle' }}"></i>
                                {{ $gatewayUp ? 'jalan' : 'tidak jalan' }}
                            </span>
                        </div>
                        <div class="ap-health-row">
                            <span>Management API</span>
                            <span>
                                <i class="status-dot {{ $managementUp ? 'status-ready' : 'status-idle' }}"></i>
                                {{ $managementUp ? 'aktif' : 'nonaktif' }}
                            </span>
                        </div>
                        <div class="ap-health-row">
                            <span>Model tersedia</span>
                            <span>{{ count($availableModels) }} model</span>
                        </div>
                        <div class="ap-health-row">
                            <span>Pemakaian 30 hari</span>
                            <span>{{ number_format($usage['grand_total']) }} token</span>
                        </div>
                    </div>
                    @unless ($gatewayUp && $managementUp)
                        <p class="ap-health-warning">Pastikan service CLIProxyAPI berjalan di server dan <code>CLIPROXY_MANAGEMENT_KEY</code> terisi di <code>.env</code>.</p>
                    @endunless
                </article>
            </div>

            <article class="ap-card ap-overview-usage">
                <div class="ap-card-head">
                    <div>
                        <p class="section-kicker">PEMAKAIAN</p>
                        <h2>30 hari terakhir</h2>
                    </div>
                    <span class="ap-usage-total">{{ number_format($usage['grand_total']) }} token</span>
                </div>
                @if ($usageRows === [])
                    <p class="ap-note">Belum ada pemakaian tercatat. Angka muncul setelah agent pertama kali memanggil model lewat gateway.</p>
                @else
                    @foreach (array_slice($usageRows, 0, 5) as $row)
                        @php $pct = $usage['grand_total'] > 0 ? round($row['total'] / $usage['grand_total'] * 100) : 0; @endphp
                        <div class="ap-usage-row">
                            <div class="ap-usage-line">
                                <span class="ap-usage-label">{{ $row['label'] }}</span>
                                <span class="ap-usage-meta">{{ number_format($row['total']) }} token · {{ $row['calls'] }} panggilan · {{ $pct }}%</span>
                            </div>
                            <div class="ap-bar"><span style="width:{{ max($pct, 2) }}%"></span></div>
                        </div>
                    @endforeach
                    <p class="ap-note" style="margin-top:12px;">Bar menunjukkan proporsi antar provider dari pencatatan ScrumSpace sendiri, bukan persentase terhadap limit langganan.</p>
                @endif
            </article>
        </section>

        {{-- Tab 2 — Provider OAuth yang aktif. --}}
        <section class="ap-panel" id="ap-panel-providers" role="tabpanel" aria-labelledby="ap-tab-providers" data-panel="providers" @if ($initialTab !== 'providers') hidden @endif>
            <div class="ap-section-head">
                <div>
                    <p class="section-kicker">DAFTAR</p>
                    <h2>Provider aktif</h2>
                </div>
                <div class="ap-head-actions">
                    <span class="count-pill">{{ count($activeProviders) }} terhubung</span>
                    <button class="button button-primary" type="button" data-open-dialog="connect-dialog">Hubungkan provider</button>
                </div>
            </div>
            <p class="ap-section-note">Bar pemakaian menunjukkan proporsi antar provider dari pencatatan ScrumSpace sendiri (bukan persentase terhadap limit langgananmu), karena gateway tidak menyediakan angka limit resmi.</p>

            @if ($hasActiveOAuth)
                <div class="ap-grid">
                    @foreach ($activeProviders as $slug => $info)
                        @php
                            $cardUsage = $usage['by_slug'][$slug] ?? null;
                            $pct = $cardUsage && $usage['grand_total'] > 0 ? round($cardUsage['total'] / $usage['grand_total'] * 100) : 0;
                        @endphp
                        <article class="ap-card ap-provider">
                            <div class="ap-provider-head">
                                <span class="agent-avatar avatar-{{ $toneFor($slug) }}">{{ mb_strtoupper(mb_substr($info['label'], 0, 1)) }}</span>
                                <div class="ap-provider-title">
                                    <h3>{{ $info['label'] }}</h3>
                                    <p>{{ $info['description'] }}</p>
                                    @if ($info['email'])
                                        <small>{{ $info['email'] }}</small>
                                    @endif
                                </div>
                                <span class="ap-status is-on"><i></i>Terhubung</span>
                            </div>

                            @if ($info['models'])
                                <div class="ap-chips">
                                    @foreach (array_slice($info['models'], 0, 6) as $model)
                                        <span class="ap-chip">{{ $model }}</span>
                                    @endforeach
                                    @if (count($info['models']) > 6)
                                        <span class="ap-chip is-more">+{{ count($info['models']) - 6 }} lainnya</span>
                                    @endif
                                </div>
                            @endif

                            <div class="ap-usage">
                                @if ($cardUsage)
                                    <div class="ap-usage-line">
                                        <span class="ap-usage-total">{{ number_format($cardUsage['total']) }} token</span>
                                        <span class="ap-usage-meta">{{ number_format($cardUsage['calls']) }} panggilan · {{ $pct }}%</span>
                                    </div>
                                    <div class="ap-bar"><span style="width:{{ max($pct, 2) }}%"></span></div>
                                    @if ($cardUsage['models'])
                                        <details class="ap-details">
                                            <summary>Rincian {{ count($cardUsage['models']) }} model</summary>
                                            <ul class="ap-models">
                                                @foreach ($cardUsage['models'] as $model)
                                                    <li>
                                                        <span>{{ $model['model'] }}</span>
                                                        <span>{{ number_format($model['total']) }} token · {{ $model['calls'] }} panggilan</span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </details>
                                    @endif
                                @else
                                    <div class="ap-usage-line">
                                        <span class="ap-usage-total">0 token</span>
                                        <span class="ap-usage-meta">30 hari terakhir</span>
                                    </div>
                                    <p class="ap-note" style="margin-top:8px;">Belum ada pemakaian tercatat.</p>
                                @endif
                            </div>

                            <div class="ap-card-foot">
                                <form method="POST" action="{{ route('ai-providers.disconnect', ['provider' => $slug]) }}">
                                    @csrf
                                    <button class="button button-danger" type="submit" onclick="return handleConfirm(event, {title: 'Putus akun?', message: 'Putus akun {{ $info['label'] }}? Kamu bisa mengaktifkannya lagi kapan pun.', confirmLabel: 'Ya, putuskan', danger: true})">Putuskan</button>
                                </form>
                            </div>
                        </article>
                    @endforeach
                </div>
            @else
                <div class="ap-card ap-empty">
                    <h3>Belum ada provider aktif</h3>
                    <p>Hubungkan Google Gemini, GPT, atau Claude lewat tombol Hubungkan provider.</p>
                </div>
            @endif

            @if ($usage['other'])
                <div class="ap-usage-other">
                    <h3>Pemakaian provider tanpa kartu di atas</h3>
                    <p>Pemakaian dari provider API-key atau label yang belum punya kartu OAuth tetap ditampilkan di sini agar tidak hilang.</p>
                    @foreach ($usage['other'] as $row)
                        @php $pct = $usage['grand_total'] > 0 ? round($row['total'] / $usage['grand_total'] * 100) : 0; @endphp
                        <div style="margin-bottom:.85rem">
                            <div style="display:flex;justify-content:space-between;gap:1rem;font-size:11px"><strong>{{ $row['provider'] }}</strong><span style="color:var(--muted)">{{ number_format($row['total']) }} token · {{ $row['calls'] }} panggilan · {{ $pct }}%</span></div>
                            <div class="ap-bar"><span style="width:{{ max($pct, 2) }}%"></span></div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Tab 3 — Akun API key (multi akun, multi key). --}}
        <section class="ap-panel" id="ap-panel-api-key" role="tabpanel" aria-labelledby="ap-tab-api-key" data-panel="api-key" @if ($initialTab !== 'api-key') hidden @endif>
            <div class="ap-section-head">
                <div>
                    <p class="section-kicker">AKUN</p>
                    <h2>Akun API key</h2>
                </div>
                <div class="ap-head-actions">
                    <span class="count-pill">{{ $apiKeyAccountCount }} akun</span>
                    @if ($apiKeys !== null && $v8Up)
                        <button class="button button-primary" type="button" data-open-dialog="api-key-dialog">Tambah akun</button>
                    @endif
                </div>
            </div>
            <p class="ap-section-note">Satu provider bisa punya beberapa akun (grup), dan satu akun bisa punya beberapa key. Gateway merotasi key di dalam satu akun dan berpindah akun saat satu akun kena limit. Key tidak pernah ditampilkan utuh — hanya 4 karakter awal dan akhir.</p>

            @if ($apiKeys === null || ! $v8Up)
                <p class="form-alert" style="margin:0">Management API v8 gateway tidak aktif / tidak dapat dihubungi — pastikan gateway versi 8.x berjalan dan <code>CLIPROXY_MANAGEMENT_KEY</code> terisi di <code>.env</code>. Setelah aktif, API key bisa dikelola dari sini.</p>
            @elseif (! $hasApiKeyAccounts)
                <div class="ap-card ap-empty">
                    <h3>Belum ada akun API key</h3>
                    <p>Tambahkan akun lewat tombol Tambah akun di atas.</p>
                </div>
            @else
                <div class="ap-grid">
                    @foreach ($apiKeys as $provider => $groups)
                        @foreach ($groups as $group)
                            <article class="ap-card ap-provider">
                                <div class="ap-provider-head">
                                    <span class="agent-avatar avatar-blue">{{ mb_strtoupper(mb_substr($apiKeyProviders[$provider] ?? $provider, 0, 1)) }}</span>
                                    <div class="ap-provider-title">
                                        <p class="ap-provider-kicker">{{ $apiKeyProviders[$provider] ?? $provider }}</p>
                                        <h3>{{ $group['name'] }}</h3>
                                        @if ($group['base_url'])
                                            <small>Endpoint: {{ $group['base_url'] }}</small>
                                        @endif
                                    </div>
                                    <span class="ap-status is-on"><i></i>Aktif</span>
                                </div>

                                @if ($group['models'])
                                    <div class="ap-chips">
                                        @foreach (array_slice($group['models'], 0, 6) as $model)
                                            <span class="ap-chip">{{ is_array($model) ? ($model['alias'] ?? $model['name']) : $model }}</span>
                                        @endforeach
                                        @if (count($group['models']) > 6)
                                            <span class="ap-chip is-more">+{{ count($group['models']) - 6 }} lainnya</span>
                                        @endif
                                    </div>
                                @endif

                                @if (! empty($group['quota']))
                                    @php $q = $group['quota']; $qpct = isset($q['usagePercent']) ? min(100, max(0, $q['usagePercent'])) : null; @endphp
                                    <div class="ap-quota">
                                        <div class="ap-quota-head">
                                            <strong>Kuota{{ isset($q['name']) ? ': '.$q['name'] : '' }}</strong>
                                            @if (isset($q['status']))<small>{{ $q['status'] }}</small>@endif
                                        </div>
                                        @if ($qpct !== null)
                                            <div class="ap-bar is-quota"><span style="width:{{ $qpct }}%"></span></div>
                                        @endif
                                        <p>
                                            @if (isset($q['usedTokens'], $q['maxTokens']))Terpakai {{ number_format($q['usedTokens']) }} dari {{ number_format($q['maxTokens']) }} token{{ $qpct !== null ? ' ('.$qpct.'%)' : '' }} · sisa {{ number_format($q['remainingTokens'] ?? 0) }} token.@endif
                                            @if (isset($q['usage'])) Pemakaian: {{ $q['usage'] }}.@endif
                                            @if (isset($q['expiresAt'])) Berlaku sampai {{ $q['expiresAt'] }}.@endif
                                            @if (count($group['keys']) > 1) Kuota key pertama.@endif
                                        </p>
                                    </div>
                                @endif

                                <div class="ap-keys-block">
                                    <p class="ap-note" style="margin:14px 0 6px;">{{ count($group['keys']) }} key pada akun ini</p>
                                    <ul class="ap-keys">
                                        @foreach ($group['keys'] as $index => $masked)
                                            <li class="ap-key">
                                                <code>{{ $masked }}</code>
                                                <form method="POST" action="{{ route('ai-providers.api-keys.keys.destroy', ['provider' => $provider, 'group' => $group['name'], 'index' => $index]) }}">
                                                    @csrf @method('DELETE')
                                                    <button class="button" type="submit" onclick="return handleConfirm(event, {title: 'Hapus key?', message: 'Hapus key {{ $masked }} dari akun {{ $group['name'] }}?{{ count($group['keys']) === 1 ? ' Ini key terakhir — akunnya ikut terhapus.' : '' }}', confirmLabel: 'Ya, hapus', danger: true})">Hapus</button>
                                                </form>
                                            </li>
                                        @endforeach
                                    </ul>
                                    <form method="POST" action="{{ route('ai-providers.api-keys.keys.store', ['provider' => $provider, 'group' => $group['name']]) }}" class="ap-inline-form">
                                        @csrf
                                        <div class="form-field">
                                            <label for="key-{{ $provider }}-{{ $group['name'] }}">Tambah key ke akun ini</label>
                                            <input type="password" id="key-{{ $provider }}-{{ $group['name'] }}" name="api_key" autocomplete="off" spellcheck="false" required minlength="8" maxlength="500">
                                        </div>
                                        <button class="button button-primary" type="submit">Tambah key</button>
                                    </form>
                                </div>

                                <details class="ap-details">
                                    <summary>Ubah daftar model</summary>
                                    <form method="POST" action="{{ route('ai-providers.api-keys.models.update', ['provider' => $provider, 'group' => $group['name']]) }}" class="ap-form-stack">
                                        @csrf
                                        <div class="form-field">
                                            <label for="models-{{ $provider }}-{{ $group['name'] }}">Satu model per baris, tambah <code>|alias</code> untuk nama tampil</label>
                                            <textarea id="models-{{ $provider }}-{{ $group['name'] }}" name="models_text" rows="4" spellcheck="false">{{ implode("\n", array_map(fn ($m) => $m['name'].(isset($m['alias']) ? '|'.$m['alias'] : ''), $group['models_raw'] ?? [])) }}</textarea>
                                        </div>
                                        <button class="button" type="submit">Simpan daftar model</button>
                                    </form>
                                </details>

                                <div class="ap-card-foot">
                                    <form method="POST" action="{{ route('ai-providers.api-keys.destroy', ['provider' => $provider, 'group' => $group['name']]) }}">
                                        @csrf @method('DELETE')
                                        <button class="button button-danger" type="submit" onclick="return handleConfirm(event, {title: 'Hapus akun?', message: 'Hapus akun {{ $group['name'] }} beserta seluruh key-nya dari gateway?', confirmLabel: 'Ya, hapus', danger: true})">Hapus akun</button>
                                    </form>
                                </div>
                            </article>
                        @endforeach
                    @endforeach
                </div>
            @endif

            @unless ($hasApiKeyAccounts)
                <p class="ap-note" style="margin-top:12px;">Provider OAuth (Gemini, GPT, Claude) dikelola di tab Provider, bukan di sini.</p>
            @endunless
        </section>
    </div>

    {{-- Dialog pilih provider OAuth: menggantikan section besar "Hubungkan
         Provider" yang dulu menumpuk di bawah halaman. --}}
    <dialog class="ap-dialog ap-connect-dialog" id="connect-dialog">
        <div class="ap-dialog-head">
            <div>
                <p class="section-kicker">HUBUNGKAN</p>
                <h2>Pilih provider login</h2>
            </div>
            <button class="button" type="button" data-dialog-close aria-label="Tutup">✕</button>
        </div>
        <p class="ap-note">Login memakai akun provider kamu sendiri. Setelah menekan Hubungkan, kamu akan diarahkan ke halaman izin provider lalu menempel kembali URL callback-nya.</p>
        <ul class="ap-connect-list">
            @foreach ($connectNames as $slug => $name)
                @isset ($providers[$slug])
                    @php $info = $providers[$slug]; @endphp
                    <li class="ap-connect-row">
                        <span class="agent-avatar avatar-{{ $toneFor($slug) }}">{{ mb_strtoupper(mb_substr($name, 0, 1)) }}</span>
                        <div class="ap-connect-main">
                            <strong>{{ $name }}</strong>
                            <small>{{ $info['description'] }}</small>
                            @if ($info['connected'] && $info['email'])
                                <small class="ap-connect-email">{{ $info['email'] }}</small>
                            @endif
                        </div>
                        @if ($info['connected'])
                            <button class="button" type="button" data-switch-tab="providers" data-dialog-close>Kelola</button>
                        @else
                            <a class="button button-primary" href="{{ route('ai-providers.connect', ['provider' => $slug]) }}">Hubungkan <span aria-hidden="true">→</span></a>
                        @endif
                    </li>
                @endisset
            @endforeach
        </ul>
        <p class="ap-note" style="margin-top:14px;">Butuh API key (Gemini, Vertex, Claude, xAI, OpenAI-compatible)? Tambahkan lewat tab API Key.</p>
    </dialog>

    @if ($apiKeys !== null && $v8Up)
        <dialog class="ap-dialog ap-api-key-dialog" id="api-key-dialog" data-auto-open="{{ session('api_key_dialog') ? '1' : '0' }}">
            <div class="ap-dialog-head">
                <div>
                    <p class="section-kicker">API KEY</p>
                    <h2>Tambah akun API key</h2>
                </div>
                <button class="button" type="button" data-dialog-close aria-label="Tutup">✕</button>
            </div>
            <p class="ap-note">Satu provider bisa punya beberapa akun (grup), dan satu akun bisa punya beberapa key. Key dikirim ke gateway dan disimpan di konfigurasinya. Tidak pernah ditampilkan lagi setelah disimpan.</p>
            @if ($errors->any())
                <div class="form-alert" style="margin-top:14px;"><ul style="margin:0;padding-left:1.1rem">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
            @endif
            @if (session('error'))
                <p class="form-alert" style="margin-top:14px;">{{ session('error') }}</p>
            @endif
            <form method="POST" action="{{ route('ai-providers.api-keys.store') }}" style="margin-top:16px">
                @csrf
                <div class="ap-dialog-grid">
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
                </div>
                <div class="form-field">
                    <label for="api_key">API key</label>
                    <input type="password" id="api_key" name="api_key" autocomplete="off" spellcheck="false" required minlength="8" maxlength="500">
                    <p class="form-hint">Key dikirim ke gateway dan disimpan di konfigurasinya. Tidak pernah ditampilkan lagi setelah disimpan. Karakter emoji/tak terlihat ditolak gateway — bila ragu, salin ulang key dari dashboard provider.</p>
                </div>
                <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-bottom:1rem">
                    <button class="button" type="button" id="check-api-key-connection">Check connection</button>
                    <span id="check-api-key-result" style="font-size:12px"></span>
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
                    <button class="button" type="button" data-dialog-close>Batal</button>
                </div>
            </form>
        </dialog>
    @endif

    <script>
        (function () {
            var shell = document.querySelector('.ap-shell');
            if (!shell) return;

            var tabs = Array.prototype.slice.call(shell.querySelectorAll('.ap-tab'));
            var panels = Array.prototype.slice.call(shell.querySelectorAll('.ap-panel'));
            var initial = shell.getAttribute('data-initial-tab') || 'overview';
            var storageKey = 'scrumspace-ai-providers-tab';
            var names = tabs.map(function (tab) { return tab.getAttribute('data-tab'); });

            function activate(name, focus) {
                if (names.indexOf(name) === -1) name = 'overview';
                tabs.forEach(function (tab) {
                    var on = tab.getAttribute('data-tab') === name;
                    tab.classList.toggle('is-active', on);
                    tab.setAttribute('aria-selected', on ? 'true' : 'false');
                    tab.setAttribute('tabindex', on ? '0' : '-1');
                    if (on && focus) tab.focus();
                });
                panels.forEach(function (panel) {
                    panel.hidden = panel.getAttribute('data-panel') !== name;
                });
                try { localStorage.setItem(storageKey, name); } catch (e) {}
                if (window.history && history.replaceState) {
                    history.replaceState(null, '', '#' + name);
                }
            }

            // Prioritas tab: hash URL > tab paksaan server (error form) > tab terakhir.
            var fromHash = (location.hash || '').replace('#', '');
            var saved = '';
            try { saved = localStorage.getItem(storageKey) || ''; } catch (e) {}

            if (names.indexOf(fromHash) !== -1) {
                activate(fromHash, false);
            } else if (initial !== 'overview') {
                activate(initial, false);
            } else if (names.indexOf(saved) !== -1) {
                activate(saved, false);
            } else {
                activate('overview', false);
            }

            tabs.forEach(function (tab, index) {
                tab.addEventListener('click', function () {
                    activate(tab.getAttribute('data-tab'), false);
                });
                tab.addEventListener('keydown', function (e) {
                    var next = null;
                    if (e.key === 'ArrowRight') next = (index + 1) % tabs.length;
                    if (e.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
                    if (e.key === 'Home') next = 0;
                    if (e.key === 'End') next = tabs.length - 1;
                    if (next === null) return;
                    e.preventDefault();
                    activate(tabs[next].getAttribute('data-tab'), true);
                });
            });

            document.querySelectorAll('[data-open-dialog]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var dialog = document.getElementById(btn.getAttribute('data-open-dialog'));
                    if (!dialog) return;
                    if (dialog.showModal) dialog.showModal();
                    else dialog.setAttribute('open', 'open');
                });
            });

            document.querySelectorAll('[data-switch-tab]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    activate(btn.getAttribute('data-switch-tab'), false);
                });
            });

            document.querySelectorAll('dialog.ap-dialog').forEach(function (dialog) {
                dialog.querySelectorAll('[data-dialog-close]').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        if (dialog.close) dialog.close();
                        else dialog.removeAttribute('open');
                    });
                });
                dialog.addEventListener('click', function (e) {
                    if (e.target === dialog && dialog.close) dialog.close();
                });
                if (dialog.getAttribute('data-auto-open') === '1') {
                    if (dialog.showModal) dialog.showModal();
                    else dialog.setAttribute('open', 'open');
                }
            });

            var checkBtn = document.getElementById('check-api-key-connection');
            var checkResult = document.getElementById('check-api-key-result');
            if (checkBtn && checkResult) {
                checkBtn.addEventListener('click', function () {
                    var form = checkBtn.closest('form');
                    var csrf = document.querySelector('meta[name="csrf-token"]');
                    checkBtn.disabled = true;
                    checkResult.textContent = 'Memeriksa…';
                    fetch('{{ route('ai-providers.api-keys.check') }}', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf ? csrf.getAttribute('content') : ''},
                        body: JSON.stringify({
                            provider: form.querySelector('[name=provider]').value,
                            api_key: form.querySelector('[name=api_key]').value,
                            base_url: form.querySelector('[name=base_url]').value,
                            models_text: form.querySelector('[name=models_text]').value,
                        }),
                    }).then(function (r) {
                        return r.json().then(function (d) { return {status: r.status, body: d}; });
                    }).then(function (res) {
                        var d = res.body;
                        if (d.ok) {
                            var msg = '✓ ' + d.message;
                            if (d.upstream_models !== undefined) msg += ' (' + d.upstream_models + ' model di upstream)';
                            if (d.missing && d.missing.length) msg += ' — tidak ditemukan di upstream: ' + d.missing.join(', ');
                            else if (d.matched && d.matched.length) msg += ' — semua model yang ditulis ditemukan di upstream.';
                            checkResult.textContent = msg;
                        } else {
                            checkResult.textContent = '✗ ' + (d.message || 'Pemeriksaan gagal.');
                        }
                    }).catch(function () {
                        checkResult.textContent = '✗ Pemeriksaan gagal (jaringan).';
                    }).finally(function () {
                        checkBtn.disabled = false;
                    });
                });
            }
        })();
    </script>
@endsection
