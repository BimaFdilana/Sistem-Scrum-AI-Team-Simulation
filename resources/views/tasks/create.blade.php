@extends('layouts.workspace', ['title' => 'Tambah task', 'breadcrumb' => 'Projects / '.$story->project->name.' / Story / Tasks / Baru'])

@section('content')
    <section class="page-heading">
        <div>
            <p class="eyebrow"><span class="eyebrow-line"></span>BACKLOG TASK</p>
            <h1>Tambah task</h1>
            <p class="page-subtitle">Task akan berstatus BACKLOG dan menyimpan revision story yang menjadi dasarnya.</p>
        </div>
        <a class="text-link" href="{{ route('stories.tasks.index', $story) }}">← Kembali ke tasks</a>
    </section>

    <section class="story-context empty-panel">
        <p class="section-kicker">PARENT STORY · REVISION {{ $story->revision }}</p>
        <h2>{{ $story->title }}</h2>
        <p>{{ $story->description ?: 'Tidak ada deskripsi tambahan.' }}</p>
        <div class="story-criteria"><span class="section-kicker">ACCEPTANCE CRITERIA</span><pre>{{ $story->acceptance_criteria }}</pre></div>
    </section>

    <form class="task-form project-form" method="POST" action="{{ route('stories.tasks.store', $story) }}">
        @csrf
        <div class="project-form-main">
            <div class="field-group">
                <label for="title">Judul task <span>*</span></label>
                <input id="title" name="title" value="{{ old('title') }}" maxlength="180" required placeholder="Contoh: Tambahkan rate limit login">
                @error('title')<small class="field-error">{{ $message }}</small>@enderror
            </div>
            <div class="field-group">
                <label for="description">Detail task <small>Opsional</small></label>
                <textarea id="description" name="description" rows="4" maxlength="5000" placeholder="Batas pekerjaan, pendekatan, atau catatan teknis">{{ old('description') }}</textarea>
                @error('description')<small class="field-error">{{ $message }}</small>@enderror
            </div>
            <div class="field-group">
                <label for="assignee_profile">Developer profile <span>*</span></label>
                <select id="assignee_profile" name="assignee_profile" required>
                    <option value="">Pilih profil</option>
                    <option value="backend-developer" @selected(old('assignee_profile') === 'backend-developer')>Backend Developer</option>
                    <option value="frontend-developer" @selected(old('assignee_profile') === 'frontend-developer')>Frontend Developer</option>
                </select>
                @error('assignee_profile')<small class="field-error">{{ $message }}</small>@enderror
            </div>
            <div class="form-actions">
                <a class="button button-ghost" href="{{ route('stories.tasks.index', $story) }}">Batal</a>
                <button class="button button-primary" type="submit">Simpan ke backlog <span aria-hidden="true">→</span></button>
            </div>
        </div>
        <aside class="project-form-aside">
            <p class="section-kicker">EXECUTION BOUNDARY</p>
            <h2>Penugasan belum memulai agent</h2>
            <p>Task disimpan sebagai BACKLOG. Agent run, perubahan source, QA, approval hasil, dan transisi DONE belum diaktifkan pada build ini.</p>
            <div class="aside-rule"><span>✓</span><span>Scope story disetujui pada revision {{ $story->revision }}</span></div>
            <div class="aside-rule"><span>1</span><span>Developer role dicatat sebagai assignee</span></div>
            <div class="aside-rule"><span>2</span><span>Task menunggu workflow execution yang terisolasi</span></div>
        </aside>
    </form>
@endsection
