@extends('layouts.workspace', ['title' => 'Hubungkan '.$meta['label'], 'breadcrumb' => 'AI Providers / Hubungkan'])

@section('content')
    <section class="page-heading">
        <div>
            <p class="eyebrow"><span class="eyebrow-line"></span>HUBUNGKAN PROVIDER</p>
            <h1>{{ $meta['label'] }}</h1>
            <p class="page-subtitle">Selesaikan login OAuth di bawah ini. Prosesnya aman: kredensial Google/ChatGPT/Claude tidak pernah disimpan website — hanya token OAuth yang dipegang gateway lokal.</p>
        </div>
        <a class="button" href="{{ route('ai-providers.index') }}">← Kembali</a>
    </section>

    @if (session('error'))
        <p class="form-alert">{{ session('error') }}</p>
    @endif

    <div style="display: grid; gap: 12px; max-width: 760px;">
        <article class="story-card">
            <div class="story-card-head"><span class="scope-pill">1</span><strong>Buka link login</strong></div>
            <p class="story-description">Klik tombol di bawah, lalu login dan tekan <strong>Allow / Izinkan</strong> di halaman provider.</p>
            <div style="margin-top: 0.75rem;">
                <a class="button button-primary" href="{{ $authUrl }}" target="_blank" rel="noopener">Buka halaman login {{ $meta['label'] }} <span aria-hidden="true">→</span></a>
            </div>
        </article>

        <article class="story-card">
            <div class="story-card-head"><span class="scope-pill">2</span><strong>Tempel URL callback</strong></div>
            <p class="story-description">Setelah menekan Allow, browser akan mencoba membuka halaman <code>localhost</code> dan gagal — itu normal. <strong>Copy seluruh URL</strong> dari address bar (yang mengandung <code>?code=...</code>) lalu tempel di bawah ini.</p>
            <form method="POST" action="{{ route('ai-providers.callback', ['provider' => $provider]) }}" style="margin-top: 0.75rem;">
                @csrf
                <div class="field-group">
                    <input type="url" name="callback_url" required maxlength="4000" placeholder="http://localhost:xxxx/...?code=..." value="{{ old('callback_url') }}">
                    @error('callback_url')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>
                <div class="form-actions" style="justify-content: flex-start; padding-top: 8px;">
                    <button class="button button-primary" type="submit">Selesaikan &amp; hubungkan</button>
                </div>
            </form>
            <p class="story-description" style="margin-top: 0.75rem;"><small>Link login kedaluwarsa dalam beberapa menit. Jika gagal, ulangi dari tombol Hubungkan di halaman Provider.</small></p>
        </article>
    </div>
@endsection
