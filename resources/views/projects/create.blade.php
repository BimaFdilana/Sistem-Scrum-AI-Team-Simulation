@extends('layouts.workspace', ['title' => 'Buat project', 'breadcrumb' => 'Projects / Baru'])

@section('content')
    <section class="page-heading">
        <div>
            <p class="eyebrow"><span class="eyebrow-line"></span>PROJECT SETUP</p>
            <h1>Buat project</h1>
            <p class="page-subtitle">Pilih project baru atau project existing dengan source code ZIP.</p>
        </div>
        <a class="text-link" href="{{ route('projects.index') }}">← Kembali ke projects</a>
    </section>

    <form class="project-form" method="POST" action="{{ route('projects.store') }}" enctype="multipart/form-data">
        @csrf
        <div class="project-form-main">
            <div class="form-section-heading"><span class="form-step">01</span><div><h2>Informasi project</h2><p>Nama dan tipe workspace yang akan dibuat.</p></div></div>

            <div class="field-group">
                <label for="name">Nama project <span>*</span></label>
                <input id="name" name="name" value="{{ old('name') }}" maxlength="160" required placeholder="Contoh: Portal internal">
                @error('name')<small class="field-error">{{ $message }}</small>@enderror
            </div>
            <div class="field-group">
                <label for="description">Deskripsi <small>Opsional</small></label>
                <textarea id="description" name="description" rows="3" maxlength="5000" placeholder="Tujuan project dan konteks penting">{{ old('description') }}</textarea>
                @error('description')<small class="field-error">{{ $message }}</small>@enderror
            </div>
            <div class="field-group">
                <label for="project_type">Jenis project <span>*</span></label>
                <select id="project_type" name="project_type" required>
                    <option value="NEW_PROJECT" @selected(old('project_type', 'NEW_PROJECT') === 'NEW_PROJECT')>Project baru — mulai dari workspace kosong</option>
                    <option value="EXISTING_PROJECT" @selected(old('project_type') === 'EXISTING_PROJECT')>Project existing — upload source code ZIP</option>
                </select>
                @error('project_type')<small class="field-error">{{ $message }}</small>@enderror
            </div>
            <div class="field-group" id="stack-field">
                <label for="stack">Stack awal <span>* untuk project baru</span></label>
                <input id="stack" name="stack" value="{{ old('stack') }}" maxlength="100" placeholder="Contoh: Laravel, PHP 8.5, SQLite">
                @error('stack')<small class="field-error">{{ $message }}</small>@enderror
            </div>

            <div class="field-group archive-field" id="archive-field" hidden>
                <label for="source_archive">Source code ZIP <span>*</span></label>
                <input id="source_archive" name="source_archive" type="file" accept=".zip,application/zip" aria-describedby="archive-help">
                <small id="archive-help" class="field-help">ZIP maksimal 100 MB. Arsip disimpan privat, diperiksa, dan dikarantina; file tidak diekstrak atau dieksekusi saat upload.</small>
                @error('source_archive')<small class="field-error">{{ $message }}</small>@enderror
            </div>

            <div class="form-actions">
                <a class="button button-ghost" href="{{ route('projects.index') }}">Batal</a>
                <button class="button button-primary" type="submit">Simpan project <span aria-hidden="true">→</span></button>
            </div>
        </div>

        <aside class="project-form-aside">
            <p class="section-kicker">TRUST BOUNDARY</p>
            <h2>Source belum menjadi workspace kerja</h2>
            <p>Project existing yang di-upload akan disimpan di private storage dengan checksum. Agent belum dapat membaca atau menjalankan source sampai proses ekstraksi aman dan workspace Git terisolasi tersedia.</p>
            <div class="aside-rule"><span>1</span><span>Validasi metadata ZIP</span></div>
            <div class="aside-rule"><span>2</span><span>Karantina private storage</span></div>
            <div class="aside-rule"><span>3</span><span>Ekstraksi terisolasi pada tahap berikutnya</span></div>
        </aside>
    </form>

    <script>
        (() => {
            const type = document.getElementById('project_type');
            const stack = document.getElementById('stack-field');
            const archive = document.getElementById('archive-field');
            const fileInput = document.getElementById('source_archive');
            const stackInput = document.getElementById('stack');
            const update = () => {
                const isExisting = type.value === 'EXISTING_PROJECT';
                archive.hidden = !isExisting;
                stack.hidden = isExisting;
                fileInput.required = isExisting;
                stackInput.required = !isExisting;
            };
            type.addEventListener('change', update);
            update();
        })();
    </script>
@endsection
