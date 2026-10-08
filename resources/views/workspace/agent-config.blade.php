@extends('layouts.workspace', ['title' => 'Konfigurasi Agent', 'breadcrumb' => 'Konfigurasi Agent'])
@section('content')
<section class="page-heading"><div><h1>Konfigurasi {{ $agent['name'] }}</h1><p class="page-subtitle">Snapshot aplikasi per request; bukan pengubahan profil native. Konsultasi saja, executor tetap terkunci.</p></div><a class="button button-secondary" href="{{ route('agents.index') }}">Kembali ke Agent</a></section>
@if (! $schemaReady)
    <p role="status">Migrasi konfigurasi belum diterapkan. Editor hanya dapat dibaca; penyimpanan belum tersedia.</p>
@else
    <p role="status">Katalog: {{ $catalog['status'] === 'online' ? 'online (metadata saja)' : 'offline — daftar model tidak tersedia' }}. Snapshot versi {{ $settings['config_version'] }}: {{ $settings['status'] }}. Model served belum dibuktikan oleh halaman ini.</p>
    <a class="button button-secondary" href="{{ route('agents.config.edit', $agent['slug']) }}">Periksa koneksi (GET metadata)</a>
@endif
@if (session('status'))<p role="status">{{ session('status') }}</p>@endif
@if ($errors->any())<div role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<form class="agent-config-form" action="{{ route('agents.config.update', $agent['slug']) }}" method="POST">
    @csrf
    @method('PUT')
    <input type="hidden" name="config_version" value="{{ $settings['config_version'] }}">
    <fieldset @disabled(! $schemaReady)>
        <legend>Provider dan model (pilih sendiri; tidak dipilih otomatis)</legend>
        <label for="agent-provider">Provider</label>
        <select id="agent-provider" name="provider">
            <option value="{{ $settings['provider'] }}">{{ $settings['provider'] ?: 'Belum dipilih' }} (snapshot tersimpan/default)</option>
            @foreach ($catalog['providers'] as $provider)
                @if ($provider['available'])<option value="{{ $provider['slug'] }}" @selected(old('provider', $settings['provider']) === $provider['slug'])>{{ $provider['name'] }}</option>@endif
            @endforeach
        </select>
        <label for="agent-model">Model</label>
        <select id="agent-model" name="model">
            <option value="{{ $settings['model'] }}">{{ $settings['model'] ?: 'Belum dipilih' }} (snapshot tersimpan/default)</option>
            @foreach ($catalog['providers'] as $provider)
                @foreach ($provider['models'] as $model)<option value="{{ $model }}" @selected(old('model', $settings['model']) === $model)>{{ $provider['name'] }} · {{ $model }}</option>@endforeach
            @endforeach
        </select>
        <p>Pilihan pasangan diverifikasi server pada simpan. Bila provider/model tidak berubah dan katalog offline, perubahan konten disimpan sebagai draft pending; request konsultasi ditolak.</p>
        <label for="agent-instructions">Instructions</label>
        <textarea id="agent-instructions" name="instructions" rows="10" maxlength="24000" required>{{ old('instructions', $settings['instructions']) }}</textarea>
        <label for="agent-soul">SOUL</label>
        <textarea id="agent-soul" name="soul" rows="7" maxlength="16000" required>{{ old('soul', $settings['soul']) }}</textarea>
        <fieldset><legend>Curated skills (inline di system message)</legend>
            @foreach ($pack['skills'] as $skill)
                <label><input type="checkbox" name="skills[]" value="{{ $skill }}" @checked(in_array($skill, old('skills', $settings['skills']), true))>{{ $skill }}</label>
            @endforeach
        </fieldset>
        <p>Persona dan skill tidak memberi izin tool. Safety policy immutable selalu mengikat. Autoload skill native API tidak dianggap aktif; body skill terpilih dikirim inline. Tidak ada tes inference di halaman ini.</p>
        <button class="button button-primary" type="submit">Simpan snapshot / draft aplikasi</button>
    </fieldset>
</form>
@if ($schemaReady)
<section class="agent-config-history"><h2>Riwayat immutable</h2>
    @forelse ($versions as $version)
    <details><summary>Versi {{ $version->config_version }} · {{ $version->actor_name }} · {{ $version->created_at }} · {{ $version->status }}</summary>
        <p>Provider: {{ $version->provider ?: 'belum dipilih' }} · Model: {{ $version->model ?: 'belum dipilih' }}</p>
        <h3>Instructions</h3><pre>{{ $version->instructions }}</pre><h3>SOUL</h3><pre>{{ $version->soul }}</pre>
        <p>Skills: {{ implode(', ', json_decode($version->skills, true)) }}</p>
        <form method="POST" action="{{ route('agents.config.restore', [$agent['slug'], $version->config_version]) }}">@csrf<input type="hidden" name="config_version" value="{{ $settings['config_version'] }}"><button type="submit" class="button button-secondary">Pulihkan sebagai versi baru</button></form>
    </details>
    @empty<p>Belum ada snapshot aplikasi tersimpan.</p>@endforelse
</section>
@endif
@endsection
