@extends('layouts.workspace', ['title' => $agent['name'], 'breadcrumb' => $agent['name']])

@php
    $statusLabels = ['PLANNING' => 'Perencanaan', 'ACTIVE' => 'Berjalan', 'COMPLETED' => 'Selesai'];
    $canSend = $chatAvailable && $selectedSprint !== null && $archived === null;
    $estimated = $sprintStories->whereNotNull('estimate_points')->count();
    $accepted = $sprintStories->where('acceptance_status', 'BERHASIL')->count();
@endphp

@section('content')
    <section class="chat-shell">
        <aside class="chat-rail" aria-label="Percakapan per sprint">
            <div class="chat-rail-head">
                <strong>Percakapan</strong>
                <span class="chat-connection {{ $chatAvailable ? 'is-connected' : 'is-disconnected' }}"><i></i>{{ $chatAvailable ? 'AI Agent terhubung' : 'AI Agent tidak terhubung' }}</span>
            </div>
            <p class="chat-rail-note">Satu sprint, satu percakapan. Laporan Scrum Master masuk ke sprint-nya sendiri.</p>

            @forelse ($projects as $project)
                <details class="chat-group" @if ($project->id === $selectedProject?->id || $loop->first) open @endif>
                    <summary>{{ $project->name }}</summary>
                    @forelse ($project->sprints as $sprint)
                        @php($thread = $threads->get($sprint->id))
                        <a class="chat-thread {{ $selectedSprint?->id === $sprint->id && $archived === null ? 'is-active' : '' }}" href="{{ route('chat.index', ['sprint' => $sprint->id]) }}" @if ($selectedSprint?->id === $sprint->id && $archived === null) aria-current="page" @endif>
                            <span class="chat-thread-name">{{ $sprint->name }}</span>
                            <span class="chat-thread-meta">
                                <span class="chat-chip chat-chip-{{ strtolower($sprint->status) }}">{{ $statusLabels[$sprint->status] ?? $sprint->status }}</span>
                                @if ($thread && $thread->report_count > 0)<span class="chat-badge" title="Laporan Scrum Master">{{ $thread->report_count }} laporan</span>@endif
                            </span>
                        </a>
                    @empty
                        <p class="chat-rail-empty">Belum ada sprint.</p>
                    @endforelse
                </details>
            @empty
                <p class="chat-rail-empty">Belum ada project. <a class="text-link" href="{{ route('projects.create') }}">Buat project</a> lalu sprint untuk mulai chat.</p>
            @endforelse

            @if ($archive->isNotEmpty())
                <details class="chat-group chat-archive" @if ($archived) open @endif>
                    <summary>Arsip chat lama</summary>
                    @foreach ($archive as $old)
                        <a class="chat-thread {{ $archived?->id === $old->id ? 'is-active' : '' }}" href="{{ route('chat.index', ['arsip' => $old->uuid]) }}">
                            <span class="chat-thread-name">{{ \Illuminate\Support\Str::limit($old->title ?: 'Percakapan', 38) }}</span>
                            <span class="chat-thread-meta"><span class="chat-badge">{{ $old->updated_at->translatedFormat('d M Y') }}</span></span>
                        </a>
                    @endforeach
                </details>
            @endif
        </aside>

        <div class="chat-main">
            <header class="chat-head">
                @if ($archived)
                    <p class="chat-crumb">Arsip chat lama</p>
                    <h1>{{ \Illuminate\Support\Str::limit($archived->title ?: 'Percakapan', 80) }}</h1>
                    <p class="chat-goal">Percakapan sebelum chat dipisah per sprint. Hanya bisa dibaca; lanjutkan di chat sprint.</p>
                @elseif ($selectedSprint)
                    <p class="chat-crumb">{{ $selectedProject?->name }} <span aria-hidden="true">/</span> Scrum Master</p>
                    <div class="chat-head-row">
                        <h1>{{ $selectedSprint->name }}</h1>
                        <span class="chat-chip chat-chip-{{ strtolower($selectedSprint->status) }}">{{ $statusLabels[$selectedSprint->status] ?? $selectedSprint->status }}</span>
                    </div>
                    @if ($selectedSprint->goal)<p class="chat-goal">{{ \Illuminate\Support\Str::limit($selectedSprint->goal, 220) }}</p>@endif
                    <div class="chat-stats">
                        <span><b>{{ $sprintStories->count() }}</b> story</span>
                        <span><b>{{ $estimated }}</b> diestimasi</span>
                        <span><b>{{ $accepted }}</b> diterima</span>
                        <a class="text-link" href="{{ route('sprints.show', $selectedSprint) }}">Buka sprint →</a>
                    </div>
                @else
                    <h1>Scrum Master</h1>
                    <p class="chat-goal">Pilih sprint di kiri untuk mulai berdiskusi dengan Scrum Master.</p>
                @endif

                <details class="chat-scope">
                    <summary>Apa yang bisa dan tidak bisa dilakukan Scrum Master di sini?</summary>
                    <div class="chat-scope-grid">
                        <div>
                            <strong>Bisa</strong>
                            <ul>
                                <li>Menjawab pertanyaan tentang sprint ini: story, poin, status penerimaan.</li>
                                <li>Merangkum hasil estimasi tim dan hasil eksekusi untuk Anda.</li>
                                <li>Membantu memperjelas story dan Sprint Goal.</li>
                                <li>Menyarankan langkah berikutnya.</li>
                            </ul>
                        </div>
                        <div>
                            <strong>Tidak bisa</strong>
                            <ul>
                                <li>Mengubah kode sendiri. Untuk itu pilih mode <strong>Ubah kode langsung</strong>: permintaan dikerjakan Developer, bukan Scrum Master.</li>
                                <li>Menyetujui izin eksekusi atau menerima story; itu keputusan Anda lewat tombol.</li>
                                <li>Mengubah backlog atau memberi estimasi sendiri.</li>
                                <li>Membuka detail server atau platform.</li>
                            </ul>
                        </div>
                    </div>
                </details>
            </header>

            <section class="chat-panel" aria-label="Chat dengan {{ $agent['name'] }}">
                <div class="chat-transcript" id="chatTranscript" aria-live="polite" aria-relevant="additions text">
                    @forelse ($messages as $message)
                        @if ($message->kind === 'change' && $message->role === 'user')
                            <article class="chat-message chat-message-user chat-message-change">
                                <span class="chat-message-label">Anda · permintaan ubah kode<time datetime="{{ $message->created_at->toIso8601String() }}">{{ $message->created_at->translatedFormat('d M, H:i') }}</time></span>
                                <p>{{ $message->content }}</p>
                            </article>
                        @elseif ($message->kind === 'change' && $message->directChange)
                            @php($change = $message->directChange)
                            <article class="chat-change is-{{ strtolower($change->status) }}" id="perubahan-{{ $change->id }}"
                                @if ($change->isActive()) data-change-active data-status-url="{{ route('chat.changes.status', $change, false) }}" @endif>
                                <header>
                                    <span class="chat-change-tag">DEVELOPER · UBAH KODE LANGSUNG</span>
                                    <span class="chat-change-status">@if ($change->isActive())<span class="chat-change-spin" aria-hidden="true"></span>@endif{{ $change->statusLabel() }}</span>
                                </header>
                                @if ($change->summary)<p>{{ $change->summary }}</p>@endif
                                @if ($change->error)<p class="chat-change-error">{{ $change->error }}</p>@endif
                                @if (! empty($change->files))
                                    <details class="chat-change-files" @if ($change->status === 'DONE') open @endif>
                                        <summary>{{ count($change->files) }} file {{ $change->status === 'DONE' ? 'diubah' : 'disentuh (tidak diterapkan)' }}</summary>
                                        <ul>@foreach ($change->files as $file)<li><code>{{ $file }}</code></li>@endforeach</ul>
                                    </details>
                                @endif
                                @if ($change->isActive())
                                    <p class="chat-change-hint">Halaman diperbarui otomatis saat selesai. Biasanya 2–10 menit.</p>
                                @elseif ($change->status === 'DONE')
                                    <p class="chat-change-hint">Sudah masuk ke kode utama. Buka demo untuk mencoba; bila tampilan tidak berubah, klik "Bangun ulang aset" di Pengaturan &amp; Demo.</p>
                                @endif
                                @if ($change->canRevert())
                                    <button type="button" class="button button-ghost chat-change-revert" data-revert-url="{{ route('chat.changes.revert', $change, false) }}">↩ Batalkan perubahan ini</button>
                                @elseif ($change->status === 'REVERTED')
                                    <p class="chat-change-hint">Dibatalkan {{ $change->reverted_at?->translatedFormat('d M, H:i') }}.</p>
                                @endif
                            </article>
                        @elseif ($message->isReport())
                            <article class="chat-report" id="laporan-{{ $message->id }}">
                                <header>
                                    <span class="chat-report-tag">LAPORAN SCRUM MASTER</span>
                                    <time datetime="{{ $message->created_at->toIso8601String() }}">{{ $message->created_at->translatedFormat('d M, H:i') }}</time>
                                </header>
                                <p>{{ $message->content }}</p>
                                @if ($message->link_url)<a class="text-link" href="{{ $message->link_url }}">{{ $message->link_label ?: 'Lihat detail' }} →</a>@endif
                            </article>
                        @else
                            <article class="chat-message chat-message-{{ $message->role }}">
                                <span class="chat-message-label">{{ $message->role === 'assistant' ? $agent['name'] : 'Anda' }}<time datetime="{{ $message->created_at->toIso8601String() }}">{{ $message->created_at->translatedFormat('d M, H:i') }}</time></span>
                                @if($message->imageUrl())
                                    <img src="{{ $message->imageUrl() }}" alt="Gambar terlampir" class="chat-message-image">
                                @endif
                                <p>{{ $message->content }}</p>
                            </article>
                        @endif
                    @empty
                        <div class="chat-empty" id="chatEmpty">
                            <span class="chat-empty-mark">✦</span>
                            <h2>{{ $selectedSprint ? 'Belum ada percakapan di sprint ini' : 'Pilih sprint untuk memulai' }}</h2>
                            <p>{{ $selectedSprint ? 'Tanyakan status sprint, minta ringkasan, atau diskusikan story. Laporan hasil estimasi dan eksekusi juga akan muncul di sini.' : 'Percakapan dan laporan Scrum Master dipisah per sprint.' }}</p>
                        </div>
                    @endforelse
                </div>

                @if ($archived)
                    <div class="chat-readonly">Arsip ini hanya bisa dibaca.@if ($selectedProject === null) Pilih sprint di kiri untuk berdiskusi.@endif</div>
                @else
                    <form class="chat-composer" id="chatForm" action="{{ $chatAction }}" method="POST">
                        @csrf
                        <input type="hidden" id="conversationId" value="{{ $conversation?->uuid }}">
                        <input type="hidden" id="sprintId" value="{{ $selectedSprint?->id }}">
                        @if ($canSend)
                            <div class="chat-mode" role="radiogroup" aria-label="Jenis pesan">
                                <label class="chat-mode-opt"><input type="radio" name="chat_mode" value="ask" checked><span>💬 Tanya Scrum Master</span></label>
                                <label class="chat-mode-opt"><input type="radio" name="chat_mode" value="change"><span>🛠 Ubah kode langsung</span></label>
                            </div>
                            <p class="chat-mode-note" id="changeNote" hidden>Developer langsung mengubah kode project sesuai permintaan Anda, <strong>tanpa alur Scrum</strong> (tanpa estimasi, izin, dan QA). Hasilnya langsung masuk ke kode utama dan bisa dibatalkan dengan satu klik.</p>
                            <div class="chat-quick" aria-label="Pertanyaan cepat">
                                <button type="button" class="chat-quick-btn" data-prompt="Ringkas status sprint ini untuk saya.">Ringkas status sprint</button>
                                <button type="button" class="chat-quick-btn" data-prompt="Story mana yang perlu perhatian atau keputusan saya sekarang?">Apa yang perlu keputusan saya?</button>
                                <button type="button" class="chat-quick-btn" data-prompt="Apa langkah berikutnya yang kamu sarankan untuk sprint ini?">Langkah berikutnya</button>
                            </div>
                        @endif
                        <label class="sr-only" for="chatInput">Pesan untuk {{ $agent['name'] }}</label>
                        <textarea id="chatInput" rows="3" maxlength="8000" placeholder="{{ $selectedSprint ? 'Tulis pesan untuk Scrum Master…' : 'Pilih sprint terlebih dahulu' }} (Enter kirim, Shift+Enter baris baru)" required {{ $canSend ? '' : 'disabled' }}></textarea>
                        <div class="chat-attach-preview" id="attachPreview" hidden>
                            <img id="attachThumb" alt="Pratinjau gambar terlampir">
                            <button type="button" class="chat-attach-remove" id="attachRemove" aria-label="Hapus gambar">×</button>
                        </div>
                        <input type="file" id="chatImage" accept="image/jpeg,image/png" hidden>
                        <div class="chat-composer-footer">
                            <span id="chatError" class="chat-error" role="status">{{ ! $chatAvailable ? 'Scrum Master belum terhubung. Pilih model di menu AI Providers.' : ($selectedSprint ? 'Tersimpan di percakapan sprint ini.' : '') }}</span>
                            <span class="chat-composer-actions">
                                <button type="button" class="button" id="attachButton" aria-label="Lampirkan gambar" title="Lampirkan gambar (JPG/PNG, maks 5 MB)" {{ $canSend ? '' : 'disabled' }}>🖼️</button>
                                <button class="button button-primary" id="chatSend" type="submit" {{ $canSend ? '' : 'disabled' }}>
                                    Kirim <span aria-hidden="true">→</span>
                                </button>
                            </span>
                        </div>
                    </form>
                @endif
            </section>
        </div>
    </section>

    <script src="{{ asset('js/chat-markdown.js') }}?v={{ filemtime(public_path('js/chat-markdown.js')) }}"></script>
    <script>
        (() => {
            // Balasan & laporan SM dari server diformat saat dimuat (Markdown → HTML).
            // Pesan pengguna tidak disentuh — tetap teks biasa.
            if (window.ScrumChatMarkdown) {
                document.querySelectorAll('.chat-message-assistant, .chat-report').forEach((article) => {
                    const p = article.querySelector(':scope > p');
                    if (!p) return;
                    const div = document.createElement('div');
                    div.className = 'md-content';
                    div.innerHTML = window.ScrumChatMarkdown.render(p.textContent);
                    p.replaceWith(div);
                });
            }

            const agentName = @json($agent['name']);
            const form = document.getElementById('chatForm');
            const transcriptEl = document.getElementById('chatTranscript');
            transcriptEl.scrollTop = transcriptEl.scrollHeight;
            // Tautan ke laporan tertentu (#laporan-N): sorot dan gulir ke sana.
            if (location.hash.startsWith('#laporan-')) {
                const target = document.querySelector(location.hash);
                if (target) { target.classList.add('is-target'); target.scrollIntoView({ block: 'center' }); }
            }
            if (!form) return;
            const input = document.getElementById('chatInput');
            const transcript = document.getElementById('chatTranscript');
            const conversationId = document.getElementById('conversationId');
            const sendButton = document.getElementById('chatSend');
            const error = document.getElementById('chatError');
            const csrf = document.querySelector('meta[name="csrf-token"]').content;
            const sprintId = document.getElementById('sprintId');
            const changeUrl = @json(route('chat.changes.store', [], false));
            const changeNote = document.getElementById('changeNote');
            const quick = document.querySelector('.chat-quick');
            const askPlaceholder = input.placeholder;
            const mode = () => (document.querySelector('input[name="chat_mode"]:checked') || {}).value || 'ask';
            document.querySelectorAll('input[name="chat_mode"]').forEach((radio) => radio.addEventListener('change', () => {
                const change = mode() === 'change';
                changeNote.hidden = !change;
                if (quick) quick.hidden = change;
                document.getElementById('attachButton').hidden = change;
                form.classList.toggle('is-change', change);
                sendButton.firstChild.textContent = change ? 'Kirim ke Developer ' : 'Kirim ';
                input.placeholder = change
                    ? 'Jelaskan perubahan kode yang Anda inginkan. Contoh: "Di halaman daftar unit, tambahkan kolom Pagu dan urutkan berdasarkan nama."'
                    : askPlaceholder;
                input.focus();
            }));
            document.querySelectorAll('.chat-quick-btn').forEach((btn) => btn.addEventListener('click', () => {
                input.value = btn.dataset.prompt;
                form.requestSubmit();
            }));

            const attachButton = document.getElementById('attachButton');
            const attachInput = document.getElementById('chatImage');
            const attachPreview = document.getElementById('attachPreview');
            const attachThumb = document.getElementById('attachThumb');
            const attachRemove = document.getElementById('attachRemove');
            let attachedFile = null;
            let attachedUrl = null;

            const clearAttachment = () => {
                attachedFile = null;
                attachInput.value = '';
                if (attachedUrl) URL.revokeObjectURL(attachedUrl);
                attachedUrl = null;
                attachPreview.hidden = true;
            };

            attachButton.addEventListener('click', () => attachInput.click());
            attachRemove.addEventListener('click', clearAttachment);
            attachInput.addEventListener('change', () => {
                const file = attachInput.files[0];
                if (!file) return;
                if (!['image/jpeg', 'image/png'].includes(file.type)) {
                    error.textContent = 'Hanya gambar JPG/PNG yang didukung.';
                    attachInput.value = '';
                    return;
                }
                if (file.size > 5 * 1024 * 1024) {
                    error.textContent = 'Ukuran gambar maksimal 5 MB.';
                    attachInput.value = '';
                    return;
                }
                clearAttachment();
                attachedFile = file;
                attachedUrl = URL.createObjectURL(file);
                attachThumb.src = attachedUrl;
                attachPreview.hidden = false;
                error.textContent = 'Gambar terlampir. Tulis pertanyaan lalu kirim.';
            });

            const addMessage = (role, content, imageUrl = null) => {
                document.getElementById('chatEmpty')?.remove();
                const article = document.createElement('article');
                article.className = `chat-message chat-message-${role}`;
                const label = document.createElement('span');
                label.className = 'chat-message-label';
                label.textContent = role === 'assistant' ? agentName : 'Anda';
                article.append(label);
                if (imageUrl) {
                    const img = document.createElement('img');
                    img.src = imageUrl;
                    img.alt = 'Gambar terlampir';
                    img.className = 'chat-message-image';
                    article.append(img);
                }
                // Balasan AI baru diformat Markdown; pesan pengguna tetap teks biasa.
                if (role === 'assistant' && window.ScrumChatMarkdown) {
                    const formatted = document.createElement('div');
                    formatted.className = 'md-content';
                    formatted.innerHTML = window.ScrumChatMarkdown.render(content);
                    article.append(formatted);
                } else {
                    const paragraph = document.createElement('p');
                    paragraph.textContent = content;
                    article.append(paragraph);
                }
                transcript.append(article);
                article.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            };

            const showTyping = () => {
                hideTyping();
                document.getElementById('chatEmpty')?.remove();
                const article = document.createElement('article');
                article.className = 'chat-message chat-message-assistant';
                article.id = 'chatTyping';
                const label = document.createElement('span');
                label.className = 'chat-message-label';
                label.textContent = agentName;
                const typing = document.createElement('span');
                typing.className = 'chat-typing';
                const dots = document.createElement('span');
                dots.className = 'chat-typing-dots';
                dots.setAttribute('aria-hidden', 'true');
                dots.append(document.createElement('i'), document.createElement('i'), document.createElement('i'));
                const text = document.createElement('span');
                text.textContent = 'sedang menyiapkan jawaban…';
                typing.append(dots, text);
                article.append(label, typing);
                transcript.append(article);
                article.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            };

            const hideTyping = () => {
                document.getElementById('chatTyping')?.remove();
            };

            // Enter = kirim, Shift+Enter = baris baru.
            input.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault();
                    form.requestSubmit();
                }
            });

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                const message = input.value.trim();
                if (!message || sendButton.disabled) return;
                if (mode() === 'change') {
                    const ok = await new Promise((resolve) => {
                        if (typeof window.showConfirm === 'function') {
                            window.showConfirm({ title: 'Ubah kode langsung?', message: 'Developer akan langsung mengubah kode project tanpa estimasi, izin, dan QA. Perubahan bisa dibatalkan setelah selesai.', confirmLabel: 'Ya, kerjakan' }).then(resolve);
                        } else {
                            resolve(window.confirm('Developer akan langsung mengubah kode project tanpa alur Scrum. Lanjutkan?'));
                        }
                    });
                    if (!ok) return;
                    sendButton.disabled = true;
                    error.textContent = 'Mengirim permintaan ke Developer…';
                    try {
                        const response = await fetch(changeUrl, {
                            method: 'POST',
                            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                            body: JSON.stringify({ message, sprint_id: sprintId.value ? Number(sprintId.value) : null }),
                        });
                        const payload = await response.json().catch(() => null);
                        if (!response.ok) throw new Error((payload && payload.message) || 'Permintaan gagal dikirim (kode ' + response.status + ').');
                        window.location.hash = 'perubahan-' + payload.change.id;
                        window.location.reload();
                    } catch (exception) {
                        error.textContent = exception.message;
                        sendButton.disabled = false;
                    }
                    return;
                }

                addMessage('user', message, attachedUrl);
                attachedUrl = null; // URL dipakai bubble pesan; jangan revoke di clearAttachment
                const fileToSend = attachedFile;
                attachedFile = null;
                attachInput.value = '';
                attachPreview.hidden = true;
                input.value = '';
                sendButton.disabled = true;
                showTyping();
                error.textContent = `${agentName} sedang menyiapkan jawaban…`;

                try {
                    let response;
                    if (fileToSend) {
                        const payload = new FormData();
                        payload.append('message', message);
                        payload.append('image', fileToSend);
                        if (conversationId.value) payload.append('conversation_id', conversationId.value);
                        if (sprintId.value) payload.append('sprint_id', sprintId.value);
                        response = await fetch(form.action, {
                            method: 'POST',
                            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                            body: payload,
                        });
                    } else {
                        response = await fetch(form.action, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrf,
                            },
                            body: JSON.stringify({
                                message,
                                conversation_id: conversationId.value || null,
                                sprint_id: sprintId.value ? Number(sprintId.value) : null,
                            }),
                        });
                    }
                    // Tunnel kadang memutus respons AI yang lambat -> body kosong.
                    // Jangan tampilkan error mentah JS ke pengguna.
                    const payload = await response.json().catch(() => null);
                    if (!response.ok) {
                        throw new Error((payload && payload.message) || 'Pesan gagal dikirim (kode ' + response.status + '). Coba lagi.');
                    }
                    if (!payload || !payload.assistant_message) {
                        throw new Error('Balasan AI tidak terbaca utuh (koneksi tunnel terputus). Muat ulang halaman — balasan mungkin sudah tersimpan.');
                    }

                    conversationId.value = payload.conversation_id;
                    hideTyping();
                    // Ganti URL gambar sementara (object URL) dengan URL permanen
                    // dari server agar gambar tidak hilang saat halaman dimuat ulang.
                    if (payload.user_message?.image_url) {
                        const lastUserMsg = transcript.querySelector('article.chat-message-user:last-of-type img.chat-message-image');
                        if (lastUserMsg) lastUserMsg.src = payload.user_message.image_url;
                    }
                    addMessage('assistant', payload.assistant_message.content);
                    error.textContent = 'Percakapan tersimpan di workspace.';
                } catch (exception) {
                    hideTyping();
                    error.textContent = exception.message || 'Koneksi gagal. Coba lagi.';
                } finally {
                    sendButton.disabled = false;
                    input.focus();
                }
            });

        })();

        // Kartu perubahan kode: pantau yang sedang berjalan, dan tombol batalkan.
        (() => {
            const csrf = document.querySelector('meta[name="csrf-token"]').content;
            const active = document.querySelectorAll('[data-change-active]');
            if (active.length) {
                const tick = async () => {
                    for (const card of active) {
                        try {
                            const res = await fetch(card.dataset.statusUrl, { headers: { Accept: 'application/json' } });
                            const data = await res.json();
                            if (!data.active) { window.location.hash = 'perubahan-' + data.id; window.location.reload(); return; }
                            const label = card.querySelector('.chat-change-status');
                            if (label && label.lastChild) label.lastChild.textContent = data.label;
                        } catch (e) { /* coba lagi pada putaran berikutnya */ }
                    }
                    setTimeout(tick, 5000);
                };
                setTimeout(tick, 5000);
            }
            document.querySelectorAll('.chat-change-revert').forEach((btn) => btn.addEventListener('click', async () => {
                const ok = typeof window.showConfirm === 'function'
                    ? await window.showConfirm({ title: 'Batalkan perubahan ini?', message: 'Kode dikembalikan seperti sebelum permintaan ini. Perubahan lain sesudahnya tidak ikut dibatalkan.', confirmLabel: 'Ya, batalkan', danger: true })
                    : window.confirm('Batalkan perubahan kode ini?');
                if (!ok) return;
                btn.disabled = true;
                const res = await fetch(btn.dataset.revertUrl, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf } });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) { alert(data.message || 'Gagal membatalkan perubahan.'); btn.disabled = false; return; }
                window.location.reload();
            }));
        })();
    </script>
@endsection
