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

    <section class="agent-directory-list">
        @foreach ($providers as $slug => $info)
            @php($connected = $info['connected'])
            <article class="directory-row">
                <span class="agent-avatar avatar-{{ $slug === 'antigravity' ? 'mint' : ($slug === 'codex' ? 'violet' : 'amber') }}">{{ mb_strtoupper(mb_substr($info['label'], 0, 1)) }}</span>
                <div class="directory-main">
                    <h2>{{ $info['label'] }}</h2>
                    <p>{{ $info['description'] }}</p>
                    @if ($connected && $info['email'])
                        <p><small>{{ $info['email'] }}</small></p>
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
@endsection
