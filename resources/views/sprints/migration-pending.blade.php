@extends('layouts.workspace', ['title' => 'Sprints menunggu migrasi', 'breadcrumb' => 'Projects / '.$project->name.' / Sprints'])

@section('content')
    <section class="page-heading">
        <div>
            <p class="eyebrow"><span class="eyebrow-line"></span>SPRINT PLANNING</p>
            <h1>{{ $project->name }} sprints</h1>
            <p class="page-subtitle">Struktur Sprint di bawah Product Backlog belum tersedia pada database aktif.</p>
        </div>
        <a class="button button-ghost" href="{{ route('projects.show', $project) }}">Ringkasan project</a>
    </section>
    <section class="notice-bar"><span class="notice-icon">i</span><p><strong>Menunggu migrasi database.</strong> Fitur baru belum menyentuh database aktif. Tidak ada Backlog, Sprint, atau story yang berubah.</p></section>
@endsection
