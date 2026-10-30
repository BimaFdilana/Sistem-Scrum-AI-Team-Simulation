@extends('layouts.workspace', ['title' => 'AI Providers', 'breadcrumb' => 'AI Providers'])

@section('content')
    <section class="page-heading">
        <div>
            <p class="eyebrow"><span class="eyebrow-line"></span>AI PROVIDERS</p>
            <h1>Provider AI</h1>
            <p class="page-subtitle">Hubungkan akun langganan AI kamu (Google, ChatGPT, Claude) lewat login OAuth. Website memakai kuota langgananmu melalui gateway lokal CLIProxyAPI — tanpa API key.</p>
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

    @if ($usage['other'])
        <section class="empty-panel">
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
@endsection
