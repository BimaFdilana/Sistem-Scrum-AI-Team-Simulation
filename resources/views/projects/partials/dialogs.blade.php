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
        <script>
            // Diskusi Fibonacci: tanpa dialog. Permintaan dikirim di latar, progresnya tampil di
            // dock mengambang kanan bawah (sama seperti eksekusi) dan tetap terlihat saat berpindah halaman.
            (() => {
                document.querySelectorAll('[data-fibonacci-form]').forEach((form) => form.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    const button = form.querySelector('button[type="submit"]');
                    const note = form.querySelector('[data-fibonacci-note]');
                    const say = (text, isError) => {
                        if (!note) return;
                        note.textContent = text;
                        note.hidden = false;
                        note.classList.toggle('field-error', !!isError);
                    };
                    button.disabled = true;
                    try {
                        const response = await fetch(form.action, {
                            method: 'POST',
                            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                            body: new FormData(form),
                        });
                        const data = await response.json().catch(() => ({}));
                        if (!response.ok) throw new Error(data.message || 'Diskusi tidak dapat dimulai.');
                        say('Diskusi dimulai. Pantau progresnya di panel mengambang kanan bawah; muat ulang halaman setelah selesai untuk melihat poin final.', false);
                        if (window.scrumDock) {
                            window.scrumDock.open();
                            window.scrumDock.refresh();
                        }
                    } catch (exception) {
                        say(exception.message, true);
                        button.disabled = false;
                    }
                }));
            })();
        </script>
    @else
        <section class="notice-bar"><span class="notice-icon">i</span><p><strong>Menunggu migrasi database Sprint Backlog.</strong> Sprint baru belum dapat disimpan sampai kolom Goal dan tanggal lama mendukung nilai otomatis. Data yang ada tidak berubah.</p></section>


    @endif
