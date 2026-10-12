@extends('layouts.workspace', ['title' => 'Hubungkan '.$meta['label'], 'breadcrumb' => 'AI Providers / Hubungkan'])

@section('content')
    <section class="page-heading">
        <div>
            <p class="eyebrow"><span class="eyebrow-line"></span>HUBUNGKAN PROVIDER</p>
            <h1>{{ $meta['label'] }}</h1>
            <p class="page-subtitle">Selesaikan login OAuth di bawah ini. Prosesnya aman: kredensial Google/ChatGPT/Claude tidak pernah disimpan website — hanya token OAuth yang dipegang gateway lokal.</p>
        </div>
        <a class="button button-secondary" href="{{ route('ai-providers.index') }}">← Kembali</a>
    </section>

    @if (session('error'))
        <p class="form-alert">{{ session('error') }}</p>
    @endif

    <section class="agent-directory-list">
        <article class="directory-row">
            <div class="directory-main">
                <h2>Langkah 1 — Buka link login</h2>
                <p>Klik tombol di bawah, lalu login dan tekan <strong>Allow / Izinkan</strong> di halaman provider.</p>
                <div class="directory-actions" style="margin-top: 0.75rem;">
                    <a class="button button-primary" href="{{ $authUrl }}" target="_blank" rel="noopener">Buka halaman login {{ $meta['label'] }} <span aria-hidden="true">→</span></a>
                </div>
            </div>
        </article>

        <article class="directory-row">
            <div class="directory-main">
                <h2>Langkah 2 — Tempel URL callback</h2>
                <p>Setelah menekan Allow, browser akan mencoba membuka halaman <code>localhost</code> dan gagal — itu normal. <strong>Copy seluruh URL</strong> dari address bar (yang mengandung <code>?code=...</code>) lalu tempel di bawah ini.</p>
                <form method="POST" action="{{ route('ai-providers.callback', ['provider' => $provider]) }}" style="margin-top: 0.75rem;">
                    @csrf
                    <div class="field-group">
                        <input type="url" name="callback_url" required maxlength="4000" placeholder="http://localhost:xxxx/...?code=..." value="{{ old('callback_url') }}">
                        @error('callback_url')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="directory-actions" style="margin-top: 0.75rem;">
                        <button class="button button-primary" type="submit">Selesaikan &amp; hubungkan</button>
                    </div>
                </form>
                <p><small>Link login kedaluwarsa dalam beberapa menit. Jika gagal, ulangi dari tombol Hubungkan di halaman Provider.</small></p>
            </div>
        </article>
    </section>
@endsection
