@extends('layouts.workspace', ['title' => 'Sprint Backlog · '.$project->name, 'breadcrumb' => 'Projects / '.$project->name.' / Sprint Backlog'])

@section('content')
    <section class="page-heading">
        <div>
            <p class="eyebrow"><span class="eyebrow-line"></span>SPRINT BACKLOG</p>
            <h1>{{ $backlog?->name ?: $project->name.' Sprint Backlog' }}</h1>
            <p class="page-subtitle">Kelola Sprint dan User Story project ini dalam satu tempat.</p>
        </div>
        <a class="button button-ghost" href="{{ route('projects.show', $project) }}">Ringkasan project</a>
    </section>

    @if (session('status'))<div class="success-banner" role="status">{{ session('status') }}</div>@endif
    @error('database')<div class="form-alert" role="alert">{{ $message }}</div>@enderror
    @error('story')<div class="form-alert" role="alert">{{ $message }}</div>@enderror
    @error('goal')<div class="form-alert" role="alert">{{ $message }}</div>@enderror
    @error('scope')<div class="form-alert" role="alert">{{ $message }}</div>@enderror
    @error('estimation')<div class="form-alert" role="alert">{{ $message }}</div>@enderror

    @if (! $estimationSchemaReady)
        <div class="notice-bar"><span class="notice-icon">i</span><p>Estimasi Fibonacci multi-agent menunggu migrasi database estimasi.</p></div>
    @endif

    <section class="empty-panel sprint-backlog-meta">
        <p class="section-kicker">SPRINT BACKLOG</p>
        <form class="project-form-main" method="POST" action="{{ route('projects.backlog.update', $project) }}">
            @csrf
            @method('PUT')
            <div class="field-group">
                <label for="backlog_name">Nama Sprint Backlog <span>*</span></label>
                <input id="backlog_name" name="name" value="{{ old('name', $backlog?->name) }}" maxlength="180" required>
                @error('name')<small class="field-error">{{ $message }}</small>@enderror
            </div>
            <div class="field-group">
                <label for="backlog_description">Deskripsi Sprint Backlog</label>
                <textarea id="backlog_description" name="description" rows="3" maxlength="5000">{{ old('description', $backlog?->description) }}</textarea>
                @error('description')<small class="field-error">{{ $message }}</small>@enderror
            </div>
            <button class="button button-ghost" type="submit">Simpan Sprint Backlog</button>
        </form>
    </section>

    <div class="section-heading sprint-backlog-sprints-heading">
        <div><p class="section-kicker">SPRINTS</p><h2>Sprint dan User Story</h2><p class="section-description">Setiap Sprint menjadi wadah bagi beberapa User Story. ID story dibuat otomatis.</p></div>
        @if ($sprintSchemaReady)
            <button class="button button-primary" type="button" data-sprint-dialog-open>＋ Buat Sprint</button>
        @endif
    </div>

    @forelse ($sprints as $sprint)
        <article class="story-card sprint-backlog-card">
            <div class="story-card-head">
                <span class="scope-pill">SPRINT</span>
                <div class="story-heading-copy"><h2>{{ $sprint->name }}</h2><span>Dibuat {{ $sprint->created_at?->format('d M Y H:i') }}</span></div>
                <span class="scope-pill {{ $sprint->status === 'ACTIVE' ? 'scope-approved' : 'scope-pending' }}">{{ $sprint->status }}</span>
            </div>
            @if ($sprint->goal)
                <div class="sprint-goal-box"><strong>Sprint Goal · {{ $sprint->goal_source === 'AI_DRAFT' ? 'draf Scrum Master AI' : 'goal lama, perlu dirumuskan ulang oleh AI' }}</strong><p>{{ $sprint->goal }}</p></div>
            @else
                <p class="empty-inline">Sprint Goal belum dibuat. Tambahkan User Story lalu minta Scrum Master AI merumuskannya.</p>
            @endif

            <div class="story-card-foot"><span>{{ $sprint->stories_count }} User Story</span><a class="button button-ghost" href="{{ route('sprints.show', $sprint) }}">Detail dan persetujuan</a></div>
            @if ($sprint->stories->isNotEmpty())
                <div class="sprint-list" aria-label="User stories {{ $sprint->name }}">
                    @foreach ($sprint->stories as $story)
                        <article class="sprint-story-row">
                            <div class="story-card-head"><span class="scope-pill">{{ $story->display_id }}</span><strong>{{ $story->actor?->name ?: 'Aktor belum dipilih' }}</strong><a class="button button-ghost" href="{{ route('stories.edit', $story) }}">Edit</a></div>
                            <p><strong>Saya Ingin:</strong> {{ $story->title }}</p>
                            <p><strong>Agar:</strong> {{ $story->sprint_purpose }}</p>
                            <div class="story-criteria"><strong>Acceptance Criteria</strong><pre>{{ $story->acceptance_criteria }}</pre></div>
                            @php($latestEstimationRun = $estimationSchemaReady ? $story->estimationRuns->first() : null)
                            @php($agreed = $latestEstimationRun && $latestEstimationRun->story_revision === $story->revision && $latestEstimationRun->status === 'COMPLETED' && $latestEstimationRun->report_sent_at && $latestEstimationRun->consensus_points === $story->estimate_points)
                            @if ($agreed)
                                @php($size = \App\Services\StoryEstimationService::category($story->estimate_points))
                                <p class="story-estimate-final"><strong>Poin Fibonacci final · Hasil kesepakatan tim:</strong> {{ $story->estimate_points }} ({{ $size }})</p>
                            @elseif ($story->estimate_points)
                                <p class="estimation-stale">Poin lama belum terbukti sebagai kesepakatan tim untuk revisi ini; Eksekusi tetap terkunci.</p>
                            @endif
                            <a class="button button-ghost" href="{{ route('stories.discussion.show', $story) }}">Lihat Diskusi Tim</a>
                            @php($latestExecution = $story->relationLoaded('executionRuns') ? $story->executionRuns->first() : null)
                            @if ($story->isAccepted())
                                <p class="story-estimate-final"><strong>Keputusan final PO:</strong> {{ $story->acceptanceLabel() }}</p>
                                <button class="button button-ghost" type="button" disabled title="Story sudah diterima PO ({{ $story->acceptanceLabel() }}) dan terkunci dari eksekusi ulang">Eksekusi User Story — terkunci</button>
                                @if ($latestExecution)
                                    <a class="button button-ghost" href="{{ route('stories.executions.show', [$story, $latestExecution]) }}">Riwayat Eksekusi</a>
                                @endif
                            @elseif ($agreed && $latestExecution && $latestExecution->isActive())
                                <a class="button button-ghost" href="{{ route('stories.executions.show', [$story, $latestExecution]) }}">Lihat Eksekusi ({{ str_replace('_', ' ', $latestExecution->status) }})</a>
                            @elseif ($agreed)
                                <form method="POST" action="{{ route('stories.executions.store', $story) }}" style="display:inline">@csrf<button class="button button-primary" type="submit" onclick="return handleConfirm(event, {title: 'Mulai eksekusi?', message: 'Mulai eksekusi user story ini? Scrum Master akan memecah menjadi task dan meminta izin sebelum agen mengubah code.', confirmLabel: 'Ya, mulai eksekusi'})">Eksekusi User Story</button></form>
                                @if ($latestExecution)
                                    <a class="button button-ghost" href="{{ route('stories.executions.show', [$story, $latestExecution]) }}">Riwayat Eksekusi</a>
                                @endif
                            @else
                                <button class="button button-ghost" type="button" disabled title="Eksekusi terkunci sampai estimasi Fibonacci disepakati">Eksekusi User Story — terkunci</button>
                            @endif
                            @if ($estimationSchemaReady && $sprint->status === 'PLANNING')
                                <div class="story-estimation-panel">
                                    <form method="POST" action="{{ route('stories.estimations.store', $story) }}" data-fibonacci-form data-story-label="{{ $story->display_id }}">
                                        @csrf
                                        <button class="button button-ghost" type="submit" @disabled($latestEstimationRun?->status === 'RUNNING')>Diskusi Fibonacci via Scrum Master</button>
                                        <p class="field-help">FE, BE dan QA memberi usulan keseluruhan story, saling menanggapi, lalu secara eksplisit menyepakati poin yang sama dalam maksimal dua putaran. Tanpa kesepakatan, tidak ada poin final.</p>
                                    </form>
                                    @if ($latestEstimationRun)
                                        @if ($latestEstimationRun->story_revision !== $story->revision)
                                            <p class="estimation-stale">Run #{{ $latestEstimationRun->id }} kedaluwarsa karena User Story berubah.</p>
                                        @else
                                            <p class="field-help">Status diskusi: <strong>{{ $latestEstimationRun->status }}</strong> · Revisi {{ $latestEstimationRun->story_revision }}</p>
                                            @php($failedAgent = $latestEstimationRun->agentRuns->where('status', 'FAILED')->sortByDesc('id')->first())
                                            @if ($failedAgent)
                                                @php($failureText = match ($failedAgent->error_code) {
                                                    'PROVIDER_OVERLOADED' => 'provider sedang sibuk',
                                                    'PROVIDER_RATE_LIMITED' => 'provider sedang dibatasi (rate limit)',
                                                    'PROVIDER_TIMEOUT' => 'provider melewati batas waktu',
                                                    'PROVIDER_CONNECTION' => 'provider tidak dapat dihubungi',
                                                    'INVALID_JSON_SCHEMA' => 'jawaban agent tidak valid',
                                                    'SAFETY_REJECTION' => 'jawaban ditolak kebijakan keamanan',
                                                    'LENGTH_REJECTION' => 'jawaban agent terlalu panjang',
                                                    'EMPTY_RESPONSE' => 'jawaban agent kosong',
                                                    'STALE_OR_CANCELLED' => 'run kedaluwarsa atau dibatalkan',
                                                    'WORKER_FAILED' => 'worker diskusi berhenti',
                                                    'WORKER_TIMEOUT' => 'worker diskusi timeout',
                                                    'WORKER_STALE' => 'worker tidak mengirim heartbeat',
                                                    default => 'permintaan agent gagal',
                                                })
                                                <p class="field-help"><strong>{{ strtoupper($failedAgent->profile_slug) }} · Putaran {{ $failedAgent->round }}:</strong> {{ $failureText }}. Periksa konfigurasi/koneksi, lalu gunakan Retry bila tersedia.</p>
                                            @endif
                                            @if ($latestEstimationRun->status === 'COMPLETED')
                                                <a class="text-link" href="{{ route('chat.index') }}">Buka laporan di chat Scrum Master →</a>
                                            @elseif ($latestEstimationRun->status === 'RUNNING' && $latestEstimationRun->created_at?->lte(now()->subMinutes(30)) && (int) $latestEstimationRun->requested_by === (int) auth()->id())
                                                <form method="POST" action="{{ route('stories.estimations.cancel', [$story, $latestEstimationRun]) }}">@csrf<button class="button button-ghost" type="submit">Hentikan run lama (lebih dari 30 menit)</button></form>
                                            @elseif ($latestEstimationRun->status === 'REPORT_PENDING')
                                                <form method="POST" action="{{ route('stories.estimations.report', [$story, $latestEstimationRun]) }}">@csrf<button class="button button-ghost" type="submit">Coba Lagi Laporan Scrum Master</button></form>
                                            @elseif (in_array($latestEstimationRun->status, ['FAILED', 'PARTIAL', 'NO_CONSENSUS'], true) && (int) $latestEstimationRun->requested_by === (int) auth()->id())
                                                <form method="POST" action="{{ route('stories.estimations.retry', [$story, $latestEstimationRun]) }}">@csrf<button class="button button-ghost" type="submit">Retry diskusi (run baru)</button></form>
                                            @endif
                                            <p class="field-help">Lihat usulan, tanggapan, dan persetujuan asli setiap agent melalui Lihat Diskusi Tim.</p>
                                        @endif
                                    @endif
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>
            @else
                <p class="empty-inline">Belum ada User Story di Sprint ini.</p>
            @endif

            @if ($sprint->status === 'PLANNING')
                <details class="inline-story-form">
                    <summary class="button button-primary">＋ Tambah User Story ke {{ $sprint->name }}</summary>
                    <form class="project-form-main" method="POST" action="{{ route('sprints.stories.store', $sprint) }}">
                        @csrf
                        <p class="field-help">Sprint ID: <strong>{{ $sprint->name }}</strong> · Story ID akan dibuat otomatis.</p>
                        <div class="field-group"><label for="title-{{ $sprint->id }}">Saya Ingin [Fitur] <span>*</span></label><textarea id="title-{{ $sprint->id }}" name="title" rows="3" maxlength="180" required>{{ old('title') }}</textarea></div>
                        @error('title')<small class="field-error">{{ $message }}</small>@enderror
                        <div class="field-group"><label for="actor-{{ $sprint->id }}">Sebagai [Aktor] <span>*</span></label><select id="actor-{{ $sprint->id }}" name="actor_id" required><option value="">Pilih aktor</option>@foreach ($actors as $actor)<option value="{{ $actor->id }}" @selected((string) old('actor_id') === (string) $actor->id)>{{ $actor->name }}</option>@endforeach</select></div>
                        @error('actor_id')<small class="field-error">{{ $message }}</small>@enderror
                        <div class="field-group"><label for="purpose-{{ $sprint->id }}">Agar [Tujuan] <span>*</span></label><textarea id="purpose-{{ $sprint->id }}" name="sprint_purpose" rows="2" maxlength="3000" required>{{ old('sprint_purpose') }}</textarea></div>
                        @error('sprint_purpose')<small class="field-error">{{ $message }}</small>@enderror
                        <div class="field-group"><label for="criteria-{{ $sprint->id }}">Acceptance Criteria <span>*</span></label><textarea id="criteria-{{ $sprint->id }}" name="acceptance_criteria" rows="4" maxlength="10000" required>{{ old('acceptance_criteria') }}</textarea></div>
                        @error('acceptance_criteria')<small class="field-error">{{ $message }}</small>@enderror
                        <button class="button button-primary" type="submit">Simpan User Story</button>
                    </form>
                </details>
                <div class="sprint-goal-actions">
                    @if ($sprintSchemaReady)
                    @if ($sprint->stories->isNotEmpty())
                        <form method="POST" action="{{ route('sprints.goal.generate', $sprint) }}">@csrf<button class="button button-ghost" type="submit">{{ $sprint->goal ? 'Buat ulang draf Sprint Goal dengan Scrum Master AI' : 'Buat Sprint Goal dengan Scrum Master AI' }}</button></form>
                        @if ($sprint->goal)<form method="POST" action="{{ route('sprints.scope.approve', $sprint) }}">@csrf<button class="button button-ghost" type="submit">PO · Setujui Sprint Backlog</button></form>@endif
                        @if ($sprint->scope_approved_revision === $sprint->revision)<form method="POST" action="{{ route('sprints.start', $sprint) }}">@csrf<button class="button button-primary" type="submit">Mulai Sprint →</button></form>@endif
                    @endif
                    @endif
                </div>
            @endif
        </article>
    @empty
        <section class="project-empty"><span class="empty-icon">◷</span><p class="section-kicker">NO SPRINTS</p><h2>Belum ada Sprint</h2><p>Buat Sprint, lalu tambahkan User Story ke dalam Sprint tersebut.</p></section>
    @endforelse

    @if ($sprintSchemaReady)
        <dialog class="sprint-modal" id="create-sprint-dialog" aria-labelledby="create-sprint-title" aria-describedby="create-sprint-description" data-open-on-load="{{ old('form_context') === 'sprint' ? 'true' : 'false' }}">
            <div class="sprint-modal-card">
                <div class="sprint-modal-heading">
                    <div>
                        <p class="section-kicker">SPRINT BACKLOG</p>
                        <h2 id="create-sprint-title">Buat Sprint</h2>
                    </div>
                    <button class="icon-button sprint-modal-close" type="button" data-sprint-dialog-close aria-label="Tutup dialog">×</button>
                </div>
                <p class="section-description" id="create-sprint-description">Sprint baru akan dimasukkan ke Sprint Backlog project ini.</p>
                <form class="project-form-main" method="POST" action="{{ route('projects.sprints.store', $project) }}">
                    @csrf
                    <input type="hidden" name="form_context" value="sprint">
                    <div class="field-group">
                        <label for="sprint_name">Nama Sprint <span>*</span></label>
                        <input id="sprint_name" name="name" value="{{ old('form_context') === 'sprint' ? old('name') : '' }}" maxlength="120" required placeholder="Contoh: Sprint 1" @if (old('form_context') === 'sprint') autofocus @endif>
                        @if (old('form_context') === 'sprint')
                            @error('name')<small class="field-error">{{ $message }}</small>@enderror
                        @endif
                    </div>
                    <p class="field-help">Tanggal tidak perlu diisi. Waktu pembuatan dicatat otomatis oleh Laravel.</p>
                    <div class="sprint-modal-actions">
                        <button class="button button-ghost" type="button" data-sprint-dialog-close>Batal</button>
                        <button class="button button-primary" type="submit">Simpan Sprint</button>
                    </div>
                </form>
            </div>
        </dialog>
        <script>
            (() => {
                const dialog = document.getElementById('create-sprint-dialog');
                if (!dialog) return;

                document.querySelectorAll('[data-sprint-dialog-open]').forEach((button) => {
                    button.addEventListener('click', () => dialog.showModal());
                });
                dialog.querySelectorAll('[data-sprint-dialog-close]').forEach((button) => {
                    button.addEventListener('click', () => dialog.close());
                });
                dialog.addEventListener('click', (event) => {
                    if (event.target === dialog) dialog.close();
                });

                if (dialog.dataset.openOnLoad === 'true') dialog.showModal();
            })();
        </script>
        <dialog class="fibonacci-progress-modal" id="fibonacci-progress-dialog" aria-labelledby="fibonacci-progress-title" aria-describedby="fibonacci-progress-description">
            <div class="fibonacci-progress-card">
                <div class="sprint-modal-heading">
                    <div>
                        <p class="section-kicker">FIBONACCI DISCUSSION</p>
                        <h2 id="fibonacci-progress-title">Diskusi Fibonacci</h2>
                    </div>
                    <button class="icon-button" type="button" data-fibonacci-close aria-label="Tutup dialog">×</button>
                </div>
                <p id="fibonacci-progress-description" class="section-description">Memulai diskusi dengan Backend, Frontend, dan QA.</p>
                <div class="fibonacci-progress-track" aria-hidden="true"><span data-fibonacci-progress-bar></span></div>
                <p class="fibonacci-progress-stage" data-fibonacci-stage>Menunggu worker...</p>
                <div class="fibonacci-progress-meta"><span data-fibonacci-round>Putaran —/2</span><span data-fibonacci-agents>Agent selesai 0/3</span></div>
                <p class="fibonacci-progress-error" data-fibonacci-error hidden></p>
                <div class="sprint-modal-actions">
                    <button class="button button-ghost" type="button" data-fibonacci-close>Tutup</button>
                    <a class="button button-primary" href="#" data-fibonacci-discussion hidden>Buka Diskusi Tim</a>
                    <button class="button button-primary" type="button" data-fibonacci-reload hidden>Muat ulang Sprint Backlog</button>
                </div>
            </div>
        </dialog>
        <script>
            (() => {
                const dialog = document.getElementById('fibonacci-progress-dialog');
                if (!dialog) return;
                const stage = dialog.querySelector('[data-fibonacci-stage]');
                const round = dialog.querySelector('[data-fibonacci-round]');
                const agents = dialog.querySelector('[data-fibonacci-agents]');
                const bar = dialog.querySelector('[data-fibonacci-progress-bar]');
                const error = dialog.querySelector('[data-fibonacci-error]');
                const discussion = dialog.querySelector('[data-fibonacci-discussion]');
                const reload = dialog.querySelector('[data-fibonacci-reload]');
                let timer = null;

                const labels = { 'backend-developer': 'Backend', 'frontend-developer': 'Frontend', 'qa-engineer': 'QA' };
                const errorLabels = {
                    PROVIDER_OVERLOADED: 'Provider sedang sibuk.', PROVIDER_RATE_LIMITED: 'Provider sedang dibatasi (rate limit, coba lagi nanti).', PROVIDER_TIMEOUT: 'Provider melewati batas waktu.',
                    PROVIDER_CONNECTION: 'Provider tidak dapat dihubungi.', INVALID_JSON_SCHEMA: 'Jawaban agent tidak valid.',
                    SAFETY_REJECTION: 'Jawaban ditolak oleh kebijakan keamanan.', LENGTH_REJECTION: 'Jawaban agent terlalu panjang.', EMPTY_RESPONSE: 'Jawaban agent kosong.', STALE_OR_CANCELLED: 'Run kedaluwarsa atau dibatalkan.',
                    WORKER_FAILED: 'Worker diskusi berhenti sebelum proses selesai.',
                    WORKER_TIMEOUT: 'Worker diskusi melewati batas waktu.',
                    WORKER_STALE: 'Worker diskusi tidak mengirim heartbeat.'
                };
                const terminal = ['COMPLETED', 'FAILED', 'PARTIAL', 'NO_CONSENSUS', 'STALE', 'CANCELLED'];
                const setProgress = (data) => {
                    const completed = Number(data.completed_agents || 0);
                    const percentage = data.status === 'COMPLETED' ? 100 : Math.min(95, Math.max(8, ((data.round - 1) * 3 + completed) / 6 * 100));
                    bar.style.width = `${percentage}%`;
                    round.textContent = `Putaran ${data.round || '—'}/2`;
                    agents.textContent = `Agent selesai ${completed}/3`;
                    stage.textContent = data.status === 'RUNNING'
                        ? (data.active_profile ? `Menunggu respons ${labels[data.active_profile] || data.active_profile}...` : 'Menjalankan diskusi agent...')
                        : data.status === 'COMPLETED' ? 'Konsensus dan laporan Scrum Master tersimpan.'
                        : data.status === 'NO_CONSENSUS' ? 'Dua putaran selesai tanpa konsensus.'
                        : `Diskusi berhenti dengan status ${data.status}.`;
                    if (data.failed_agent) {
                        error.hidden = false;
                        error.textContent = `${labels[data.failed_agent.profile] || data.failed_agent.profile} · Putaran ${data.failed_agent.round}: ${errorLabels[data.failed_agent.error_code] || 'Permintaan agent gagal.'}`;
                    }
                    if (terminal.includes(data.status)) {
                        clearTimeout(timer);
                        discussion.hidden = false;
                        reload.hidden = false;
                    }
                };
                const poll = async (url) => {
                    try {
                        const response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                        if (!response.ok) throw new Error('Status diskusi tidak dapat dibaca.');
                        const data = await response.json();
                        setProgress(data);
                        if (!terminal.includes(data.status)) timer = setTimeout(() => poll(url), 1500);
                    } catch (exception) {
                        error.hidden = false;
                        error.textContent = exception.message;
                        timer = setTimeout(() => poll(url), 3000);
                    }
                };
                document.querySelectorAll('[data-fibonacci-form]').forEach((form) => form.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    const button = form.querySelector('button[type="submit"]');
                    button.disabled = true;
                    dialog.querySelector('#fibonacci-progress-title').textContent = `Diskusi ${form.dataset.storyLabel}`;
                    error.hidden = true; discussion.hidden = true; reload.hidden = true; bar.style.width = '8%';
                    dialog.showModal();
                    try {
                        const response = await fetch(form.action, { method: 'POST', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: new FormData(form) });
                        const data = await response.json();
                        if (!response.ok) throw new Error(data.message || 'Diskusi tidak dapat dimulai.');
                        discussion.href = data.discussion_url;
                        poll(data.status_url);
                    } catch (exception) {
                        error.hidden = false; error.textContent = exception.message; stage.textContent = 'Diskusi tidak dapat dimulai.'; button.disabled = false;
                    }
                }));
                dialog.querySelectorAll('[data-fibonacci-close]').forEach((button) => button.addEventListener('click', () => dialog.close()));
                reload.addEventListener('click', () => window.location.reload());
            })();
        </script>
    @else
        <section class="notice-bar"><span class="notice-icon">i</span><p><strong>Menunggu migrasi database Sprint Backlog.</strong> Sprint baru belum dapat disimpan sampai kolom Goal dan tanggal lama mendukung nilai otomatis. Data yang ada tidak berubah.</p></section>
    @endif
@endsection
