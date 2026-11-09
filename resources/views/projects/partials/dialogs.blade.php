{{-- Dialog buat sprint dan dialog progres diskusi Fibonacci. --}}
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
