@extends('layouts.workspace', ['title' => $agent['name'], 'breadcrumb' => $agent['name']])

@section('content')
    <section class="page-heading chat-heading">
        <div>
            <p class="eyebrow"><span class="eyebrow-line"></span>AI AGENT CHAT</p>
            <h1>{{ $agent['name'] }}</h1>
            <p class="page-subtitle">Konsultasi sesuai spesialisasi. Respons AI adalah masukan; chat ini tidak mengubah source code atau menjalankan task.</p>
        </div>
        <span class="chat-connection {{ $chatAvailable ? 'is-connected' : 'is-disconnected' }}">
            <i></i>{{ $chatAvailable ? 'AI Agent terhubung' : 'AI Agent tidak terhubung' }}
        </span>
    </section>

    <section class="chat-panel" aria-label="Chat dengan {{ $agent['name'] }}">
        <div class="chat-transcript" id="chatTranscript" aria-live="polite" aria-relevant="additions text">
            @forelse ($messages as $message)
                <article class="chat-message chat-message-{{ $message->role }}">
                    <span class="chat-message-label">{{ $message->role === 'assistant' ? $agent['name'] : 'Anda' }}</span>
                    <p>{{ $message->content }}</p>
                </article>
            @empty
                <div class="chat-empty" id="chatEmpty">
                    <span class="chat-empty-mark">✦</span>
                    <h2>Mulai percakapan</h2>
                    <p>Tanyakan hal yang sesuai dengan peran {{ $agent['name'] }}.</p>
                </div>
            @endforelse
        </div>

        <form class="chat-composer" id="chatForm" action="{{ $chatAction }}" method="POST">
            @csrf
            <input type="hidden" id="conversationId" value="{{ $conversation?->uuid }}">
            <label class="sr-only" for="chatInput">Pesan untuk {{ $agent['name'] }}</label>
            <textarea id="chatInput" rows="3" maxlength="8000" placeholder="Tulis pesan untuk {{ $agent['name'] }}… (Enter untuk kirim, Shift+Enter baris baru)" required {{ $chatAvailable ? '' : 'disabled' }}></textarea>
            <div class="chat-attach-preview" id="attachPreview" hidden>
                <img id="attachThumb" alt="Pratinjau gambar terlampir">
                <button type="button" class="chat-attach-remove" id="attachRemove" aria-label="Hapus gambar">×</button>
            </div>
            <input type="file" id="chatImage" accept="image/jpeg,image/png" hidden>
            <div class="chat-composer-footer">
                <span id="chatError" class="chat-error" role="status">{{ $chatAvailable ? 'Percakapan tersimpan di workspace.' : 'Chat AI Agent belum tersedia. Pesan belum dapat dikirim.' }}</span>
                <span class="chat-composer-actions">
                    <button type="button" class="button" id="attachButton" aria-label="Lampirkan gambar" title="Lampirkan gambar (JPG/PNG, maks 5 MB)" {{ $chatAvailable ? '' : 'disabled' }}>🖼️</button>
                    <button class="button button-primary" id="chatSend" type="submit" {{ $chatAvailable ? '' : 'disabled' }}>
                        Kirim <span aria-hidden="true">→</span>
                    </button>
                </span>
            </div>
        </form>
    </section>

    <script>
        (() => {
            const agentName = @json($agent['name']);
            const form = document.getElementById('chatForm');
            const input = document.getElementById('chatInput');
            const transcript = document.getElementById('chatTranscript');
            const conversationId = document.getElementById('conversationId');
            const sendButton = document.getElementById('chatSend');
            const error = document.getElementById('chatError');
            const csrf = document.querySelector('meta[name="csrf-token"]').content;
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
                const paragraph = document.createElement('p');
                paragraph.textContent = content;
                article.append(paragraph);
                transcript.append(article);
                article.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            };

            // Kartu konfirmasi perintah perbaikan dari Scrum Master.
            const addRepairCard = (card) => {
                const article = document.createElement('article');
                article.className = 'chat-message chat-message-assistant repair-card';
                const label = document.createElement('span');
                label.className = 'chat-message-label';
                label.textContent = 'Perintah Perbaikan — butuh konfirmasi';
                article.append(label);

                const title = document.createElement('strong');
                title.textContent = card.title;
                article.append(title);

                const summary = document.createElement('p');
                summary.textContent = card.summary;
                article.append(summary);

                if (card.risky) {
                    const warn = document.createElement('p');
                    warn.className = 'repair-card-warn';
                    warn.textContent = '⚠️ ' + (card.risk_note || 'Perintah terdeteksi berisiko.');
                    article.append(warn);
                }

                let projectSelect = null;
                if (card.needs_project) {
                    const wrap = document.createElement('label');
                    wrap.className = 'repair-card-project';
                    wrap.append(document.createTextNode('Project: '));
                    projectSelect = document.createElement('select');
                    card.projects.forEach((p) => {
                        const opt = document.createElement('option');
                        opt.value = p.id;
                        opt.textContent = p.name;
                        projectSelect.append(opt);
                    });
                    wrap.append(projectSelect);
                    article.append(wrap);
                } else if (card.project_name) {
                    const proj = document.createElement('p');
                    proj.className = 'repair-card-project-name';
                    proj.textContent = 'Project: ' + card.project_name;
                    article.append(proj);
                }

                const actions = document.createElement('div');
                actions.className = 'repair-card-actions';
                const confirmBtn = document.createElement('button');
                confirmBtn.type = 'button';
                confirmBtn.className = 'button button-primary';
                confirmBtn.textContent = 'Konfirmasi';
                const cancelBtn = document.createElement('button');
                cancelBtn.type = 'button';
                cancelBtn.className = 'button';
                cancelBtn.textContent = 'Batalkan';
                actions.append(confirmBtn, cancelBtn);
                article.append(actions);

                const done = (text, link) => {
                    actions.remove();
                    if (projectSelect) projectSelect.disabled = true;
                    const note = document.createElement('p');
                    note.className = 'repair-card-note';
                    note.textContent = text;
                    article.append(note);
                    if (link) {
                        const a = document.createElement('a');
                        a.href = link;
                        a.className = 'repair-card-link';
                        a.textContent = 'Buka menu Perintah Perbaikan →';
                        article.append(a);
                    }
                };
                const postCard = async (url, body) => {
                    const response = await fetch(url, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                        body: JSON.stringify(body || {}),
                    });
                    const payload = await response.json();
                    if (!response.ok) throw new Error(payload.message || 'Gagal memproses perintah.');
                    return payload;
                };

                confirmBtn.addEventListener('click', async () => {
                    confirmBtn.disabled = true;
                    cancelBtn.disabled = true;
                    try {
                        const body = projectSelect ? { project_id: Number(projectSelect.value) } : {};
                        const payload = await postCard(`/repair-orders/${card.id}/confirm`, body);
                        done('✓ ' + payload.message, payload.redirect_url);
                        error.textContent = 'Perintah perbaikan tersimpan di menu.';
                    } catch (e) {
                        confirmBtn.disabled = false;
                        cancelBtn.disabled = false;
                        error.textContent = e.message || 'Konfirmasi gagal.';
                    }
                });
                cancelBtn.addEventListener('click', async () => {
                    confirmBtn.disabled = true;
                    cancelBtn.disabled = true;
                    try {
                        await postCard(`/repair-orders/${card.id}/cancel`);
                        done('Perintah dibatalkan. Klasifikasi saya salah? Tulis ulang perintahnya dengan lebih jelas.');
                        error.textContent = 'Percakapan tersimpan di workspace.';
                    } catch (e) {
                        confirmBtn.disabled = false;
                        cancelBtn.disabled = false;
                        error.textContent = e.message || 'Pembatalan gagal.';
                    }
                });

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
                            }),
                        });
                    }
                    const payload = await response.json();
                    if (!response.ok) throw new Error(payload.message || 'Pesan gagal dikirim.');

                    conversationId.value = payload.conversation_id;
                    hideTyping();
                    addMessage('assistant', payload.assistant_message.content);
                    if (payload.repair_order) addRepairCard(payload.repair_order);
                    error.textContent = 'Percakapan tersimpan di workspace.';
                } catch (exception) {
                    hideTyping();
                    error.textContent = exception.message || 'Koneksi gagal. Coba lagi.';
                } finally {
                    sendButton.disabled = false;
                    input.focus();
                }
            });

            transcript.scrollTop = transcript.scrollHeight;
        })();
    </script>
@endsection
