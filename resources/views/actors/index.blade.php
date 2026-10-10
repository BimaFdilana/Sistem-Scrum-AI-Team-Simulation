@extends('layouts.workspace', ['title' => 'Aktor', 'breadcrumb' => 'Aktor'])

@section('content')
    <section class="page-heading">
        <div>
            <p class="eyebrow"><span class="eyebrow-line"></span>WORKSPACE SETUP</p>
            <h1>Aktor</h1>
            <p class="page-subtitle">Kelola daftar aktor yang dapat dipilih pada setiap story di Sprint.</p>
        </div>
        @if ($schemaReady)
            <a class="button button-primary" href="{{ route('actors.create') }}">＋ Tambah aktor</a>
        @endif
    </section>

    @if (session('status'))<div class="success-banner" role="status">{{ session('status') }}</div>@endif
    @error('actor')<div class="form-alert" role="alert">{{ $message }}</div>@enderror

    @if (! $schemaReady)
        <section class="notice-bar"><span class="notice-icon">i</span><p><strong>CRUD aktor belum aktif.</strong> Perubahan database disiapkan, tetapi migrasi belum dijalankan. Tidak ada perubahan data sampai Product Owner menyetujui migrasi.</p></section>
    @else
        <section class="project-list" aria-label="Daftar aktor">
        @forelse ($actors as $actor)
            <article class="project-row actor-row">
                <span class="project-mark">{{ mb_strtoupper(mb_substr($actor->name, 0, 1)) }}</span>
                <span class="project-main"><strong>{{ $actor->name }}</strong><small>{{ $actor->description ?: 'Belum ada deskripsi' }}</small></span>
                <span class="project-type">{{ $actor->stories_count }} story</span>
                <span class="project-status"><i></i>{{ $actor->is_active ? 'AKTIF' : 'NONAKTIF' }}</span>
                <span class="actor-actions" aria-label="Aksi actor {{ $actor->name }}">
                    <a class="button button-ghost" href="{{ route('actors.edit', $actor) }}" aria-label="Edit actor {{ $actor->name }}">Edit</a>
                    @if ($actor->stories_count === 0)
                        <form method="POST" action="{{ route('actors.destroy', $actor) }}" onsubmit="return confirm('Hapus aktor ini?')">
                            @csrf
                            @method('DELETE')
                            <button class="button button-danger" type="submit" aria-label="Hapus actor {{ $actor->name }}">Hapus</button>
                        </form>
                    @endif
                </span>
            </article>
        @empty
            <p class="empty-inline">Belum ada aktor.</p>
        @endforelse
        </section>
    @endif
@endsection
