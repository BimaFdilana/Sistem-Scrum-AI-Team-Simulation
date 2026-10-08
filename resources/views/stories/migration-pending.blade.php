@extends('layouts.workspace', ['title' => 'Backlog menunggu migrasi', 'breadcrumb' => 'Projects / '.$project->name.' / Backlog'])

@section('content')
    <section class="page-heading">
        <div>
            <p class="eyebrow"><span class="eyebrow-line"></span>PRODUCT BACKLOG</p>
            <h1>{{ $project->name }} backlog</h1>
            <p class="page-subtitle">Struktur backlog baru belum tersedia pada database yang sedang digunakan.</p>
        </div>
        <a class="button button-ghost" href="{{ route('projects.show', $project) }}">Ringkasan project</a>
    </section>
    @error('database')<div class="form-alert" role="alert">{{ $message }}</div>@enderror
    <section class="notice-bar"><span class="notice-icon">i</span><p><strong>Migrasi database diperlukan.</strong> Perubahan kode sudah tersedia, tetapi belum ada migrasi yang dijalankan pada database aktif. Backlog, Sprint, dan story belum diubah.</p></section>
@endsection
