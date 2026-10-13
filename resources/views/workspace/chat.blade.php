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
            <div class="chat-composer-footer">
                <span id="chatError" class="chat-error" role="status">{{ $chatAvailable ? 'Percakapan tersimpan di workspace.' : 'Chat AI Agent belum tersedia. Pesan belum dapat dikirim.' }}</span>
                <button class="button button-primary" id="chatSend" type="submit" {{ $chatAvailable ? '' : 'disabled' }}>
                    Kirim <span aria-hidden="true">→</span>
                </button>
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

            const addMessage = (role, content) => {
                document.getElementById('chatEmpty')?.remove();
                const article = document.createElement('article');
                article.className = `chat-message chat-message-${role}`;
                const label = document.createElement('span');
                label.className = 'chat-message-label';
                label.textContent = role === 'assistant' ? agentName : 'Anda';
                const paragraph = document.createElement('p');
                paragraph.textContent = content;
                article.append(label, paragraph);
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

                addMessage('user', message);
                input.value = '';
                sendButton.disabled = true;
                showTyping();
                error.textContent = `${agentName} sedang menyiapkan jawaban…`;

                try {
                    const response = await fetch(form.action, {
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
                    const payload = await response.json();
                    if (!response.ok) throw new Error(payload.message || 'Pesan gagal dikirim.');

                    conversationId.value = payload.conversation_id;
                    hideTyping();
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

            transcript.scrollTop = transcript.scrollHeight;
        })();
    </script>
@endsection
