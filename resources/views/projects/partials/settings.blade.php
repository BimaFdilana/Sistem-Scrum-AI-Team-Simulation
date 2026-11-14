{{-- Pengaturan project: sumber, nama backlog, dan gerbang tes. --}}
<div class="bk-settings">
    <section class="empty-panel">
        <div class="panel-heading"><div><p class="section-kicker">SUMBER PROJECT</p><h2>{{ $project->source_repository_url ? 'GitHub repository' : 'Laravel baru' }}</h2></div></div>
        @if ($project->source_repository_url)
            <p class="archive-name">{{ $project->source_repository_url }}</p>
            <div class="archive-meta"><span>Branch: {{ $project->source_branch ?: 'default branch' }}</span><span>Commit: <code>{{ $project->source_commit_sha ?: 'pending' }}</code></span></div>
        @else
            <p class="archive-name">Kerangka Laravel baru dibuat otomatis oleh sistem.</p>
        @endif
        @if ($project->source_error)
            <div class="approval-rule"><span class="status-dot status-danger"></span><span>{{ $project->source_error }}</span></div>
        @endif
        <form class="bk-inline-form" method="POST" action="{{ route('projects.backlog.update', $project) }}">
            @csrf
            @method('PUT')
            <label for="backlog_name">Nama Sprint Backlog</label>
            <input id="backlog_name" name="name" value="{{ old('name', $backlog?->name) }}" maxlength="180" required>
            @error('name')<small class="field-error">{{ $message }}</small>@enderror
            <label for="backlog_description">Deskripsi Sprint Backlog</label>
            <textarea id="backlog_description" name="description" rows="2" maxlength="5000">{{ old('description', $backlog?->description) }}</textarea>
            @error('description')<small class="field-error">{{ $message }}</small>@enderror
            <button class="button button-ghost" type="submit">Simpan</button>
        </form>
    </section>

    <section class="empty-panel">
        <div class="panel-heading"><div><p class="section-kicker">GERBANG TES QA</p><h2>Perintah tes project (opsional)</h2></div>@if ($project->test_command)<span class="soft-badge">aktif</span>@endif</div>
        <p>Sebelum review QA, gerbang selalu menjalankan <code>php -l</code> untuk file PHP yang berubah. Perintah di bawah bersifat opsional dan dijalankan di worktree task bila diisi.</p>
        <form class="bk-inline-row" method="POST" action="{{ route('projects.test-command.update', $project) }}">
            @csrf
            @method('PUT')
            <input type="text" name="test_command" value="{{ old('test_command', $project->test_command) }}" placeholder="Kosong = gerbang tes mati">
            <button class="button button-primary" type="submit">Simpan</button>
        </form>
        @error('test_command')<p class="field-error">{{ $message }}</p>@enderror
    </section>
</div>
