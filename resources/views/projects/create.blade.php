@extends('layouts.workspace', ['title' => 'Buat project', 'breadcrumb' => 'Projects / Baru'])

@section('content')
    <section class="page-heading">
        <div>
            <p class="eyebrow"><span class="eyebrow-line"></span>PROJECT SETUP</p>
            <h1>Buat project</h1>
            <p class="page-subtitle">Mulai workspace Laravel baru atau impor repository public GitHub.</p>
        </div>
        <a class="text-link" href="{{ route('projects.index') }}">← Kembali ke projects</a>
    </section>

    <form class="project-form" method="POST" action="{{ route('projects.store') }}">
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
                    <option value="NEW_PROJECT" @selected(old('project_type', 'NEW_PROJECT') === 'NEW_PROJECT')>Project baru — mulai dari workspace Laravel kosong</option>
                    <option value="EXISTING_PROJECT" @selected(old('project_type') === 'EXISTING_PROJECT')>Import existing — repository public GitHub</option>
                </select>
                @error('project_type')<small class="field-error">{{ $message }}</small>@enderror
            </div>
            <div class="field-group" id="stack-field">
                <label for="stack">Stack awal <span>*</span></label>
                <input id="stack" name="stack" value="{{ old('stack', 'Laravel') }}" maxlength="100" placeholder="Laravel">
                <small class="field-help">MVP hanya menerima project Laravel.</small>
                @error('stack')<small class="field-error">{{ $message }}</small>@enderror
            </div>

            <div class="field-group" id="github-field" hidden>
                <label for="github_repository_url">URL repository public GitHub <span>*</span></label>
                <input id="github_repository_url" name="github_repository_url" value="{{ old('github_repository_url') }}" maxlength="500" inputmode="url" placeholder="https://github.com/owner/repository">
                <small class="field-help">Gunakan URL HTTPS public GitHub tanpa credential, query, atau fragment. Repository private ditolak.</small>
                @error('github_repository_url')<small class="field-error">{{ $message }}</small>@enderror
            </div>
            <div class="field-group" id="branch-field" hidden>
                <label for="github_branch">Branch <small>Opsional</small></label>
                <input id="github_branch" name="github_branch" value="{{ old('github_branch') }}" maxlength="255" placeholder="Kosongkan untuk default branch">
                @error('github_branch')<small class="field-error">{{ $message }}</small>@enderror
            </div>

            <div class="form-actions">
                <a class="button button-ghost" href="{{ route('projects.index') }}">Batal</a>
                <button class="button button-primary" type="submit">Simpan project <span aria-hidden="true">→</span></button>
            </div>
        </div>

        <aside class="project-form-aside">
            <p class="section-kicker">TRUST BOUNDARY</p>
            <h2>Repository belum menjadi workspace kerja</h2>
            <p>Repository public diverifikasi di worker terpisah, di-clone ke private workspace, lalu diperiksa sebagai project Laravel. Kode tidak dijalankan dan agent belum diberi akses menulis.</p>
            <div class="aside-rule"><span>1</span><span>Verifikasi akses public GitHub</span></div>
            <div class="aside-rule"><span>2</span><span>Clone baseline ke private workspace</span></div>
            <div class="aside-rule"><span>3</span><span>Validasi struktur Laravel tanpa eksekusi</span></div>
        </aside>
    </form>

    <script>
        (() => {
            const type = document.getElementById('project_type');
            const stack = document.getElementById('stack-field');
            const github = document.getElementById('github-field');
            const branch = document.getElementById('branch-field');
            const githubInput = document.getElementById('github_repository_url');
            const stackInput = document.getElementById('stack');
            const update = () => {
                const isExisting = type.value === 'EXISTING_PROJECT';
                github.hidden = !isExisting;
                branch.hidden = !isExisting;
                stack.hidden = isExisting;
                githubInput.required = isExisting;
                stackInput.required = !isExisting;
            };
            type.addEventListener('change', update);
            update();
        })();
    </script>
@endsection
