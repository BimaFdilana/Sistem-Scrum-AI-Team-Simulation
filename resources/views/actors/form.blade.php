@extends('layouts.workspace', ['title' => $actor->exists ? 'Edit aktor' : 'Tambah aktor', 'breadcrumb' => 'Aktor / '.($actor->exists ? $actor->name : 'Baru')])

@section('content')
    <section class="page-heading">
        <div>
            <p class="eyebrow"><span class="eyebrow-line"></span>WORKSPACE SETUP</p>
            <h1>{{ $actor->exists ? 'Edit aktor' : 'Tambah aktor' }}</h1>
            <p class="page-subtitle">Aktor tersedia sebagai pilihan saat story ditambahkan ke Sprint.</p>
        </div>
        <a class="text-link" href="{{ route('actors.index') }}">← Kembali ke aktor</a>
    </section>

    <form class="project-form" method="POST" action="{{ $actor->exists ? route('actors.update', $actor) : route('actors.store') }}">
        @csrf
        @if ($actor->exists) @method('PATCH') @endif
        <div class="project-form-main">
            <div class="field-group">
                <label for="name">Nama aktor <span>*</span></label>
                <input id="name" name="name" value="{{ old('name', $actor->name) }}" maxlength="120" required>
                @error('name')<small class="field-error">{{ $message }}</small>@enderror
            </div>
            <div class="field-group">
                <label for="description">Deskripsi <small>Opsional</small></label>
                <textarea id="description" name="description" rows="4" maxlength="2000">{{ old('description', $actor->description) }}</textarea>
                @error('description')<small class="field-error">{{ $message }}</small>@enderror
            </div>
            @if ($actor->exists)
                <label class="field-group"><span>Status</span><span><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $actor->is_active))> Aktif</span></label>
            @endif
            <div class="form-actions">
                <a class="button button-ghost" href="{{ route('actors.index') }}">Batal</a>
                <button class="button button-primary" type="submit">Simpan aktor</button>
            </div>
        </div>
    </form>
@endsection
