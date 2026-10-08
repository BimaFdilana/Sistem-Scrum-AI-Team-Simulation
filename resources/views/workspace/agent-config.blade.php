@extends('layouts.workspace', ['title' => 'Konfigurasi Agent', 'breadcrumb' => 'Konfigurasi Agent'])
@section('content')
<section class="page-heading"><div><h1>Konfigurasi {{ $agent['name'] }}</h1><p class="page-subtitle">Pilih model untuk profil Hermes peran ini. Instruksi, SOUL, dan skill dikelola hanya di profil native Hermes; website tidak mengubahnya.</p></div><a class="button button-secondary" href="{{ route('agents.index') }}">Kembali ke Agent</a></section>
@if (! $schemaReady)
    <p role="status">Migrasi konfigurasi belum diterapkan. Penyimpanan belum tersedia.</p>
@else
    <p role="status">Katalog: {{ $catalog['status'] === 'online' ? 'online (metadata saja)' : 'offline — daftar model tidak tersedia' }}. Versi {{ $settings['config_version'] }}: {{ $settings['status'] }}. Model served belum dibuktikan oleh halaman ini.</p>
    <a class="button button-secondary" href="{{ route('agents.config.edit', $agent['slug']) }}">Periksa koneksi (GET metadata)</a>
@endif
@if (session('status'))<p role="status">{{ session('status') }}</p>@endif
@if ($errors->any())<div role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<form class="agent-config-form" action="{{ route('agents.config.update', $agent['slug']) }}" method="POST">
    @csrf
    @method('PUT')
    <input type="hidden" name="config_version" value="{{ $settings['config_version'] }}">
    <fieldset @disabled(! $schemaReady || $catalog['status'] !== 'online')>
        <legend>Model</legend>
        <label for="agent-model">Model (provider / model)</label>
        <select id="agent-model" name="selection" required>
            <option value="">Pilih model terverifikasi</option>
            @foreach ($catalog['providers'] as $provider)
                @if ($provider['available'])
                    @foreach ($provider['models'] as $model)
                        @php($selection = json_encode(['provider' => $provider['slug'], 'model' => $model], JSON_THROW_ON_ERROR))
                        <option value="{{ $selection }}" @selected(old('selection', json_encode(['provider' => $settings['provider'], 'model' => $settings['model']])) === $selection)>{{ $provider['name'] }} / {{ $model }}</option>
                    @endforeach
                @endif
            @endforeach
        </select>
        <p>Model harus ada dalam katalog terautentikasi untuk peran ini saat disimpan. Tidak ada pemilihan otomatis atau penyimpanan offline.</p>
        <button class="button button-primary" type="submit">Simpan model</button>
    </fieldset>
</form>
@if ($schemaReady)
<section class="agent-config-history"><h2>Riwayat model immutable</h2>
    @forelse ($versions as $version)
    <details><summary>Versi {{ $version->config_version }} · {{ $version->actor_name }} · {{ $version->created_at }} · {{ $version->status }}</summary>
        <p>Provider: {{ $version->provider }} · Model: {{ $version->model }}</p>
        <form method="POST" action="{{ route('agents.config.restore', [$agent['slug'], $version->config_version]) }}">@csrf<input type="hidden" name="config_version" value="{{ $settings['config_version'] }}"><button type="submit" class="button button-secondary">Pulihkan model sebagai versi baru</button></form>
    </details>
    @empty<p>Belum ada model tersimpan.</p>@endforelse
</section>
@endif
@endsection
