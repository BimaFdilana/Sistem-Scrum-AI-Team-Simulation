{{-- Satu pesan chat SM: obrolan, perintah untuk tim, atau kartu laporan. --}}
@if ($message->kind === 'command' && $message->role === 'user')
    @php($cmdRun = $message->executionRun)
    <article class="chat-message chat-message-user chat-message-command">
        <span class="chat-message-label">Anda · perintah untuk tim<time datetime="{{ $message->created_at->toIso8601String() }}">{{ $message->created_at->translatedFormat('d M, H:i') }}</time></span>
        <p>{{ $message->content }}</p>
        @if ($cmdRun)
            @php($cmdActive = in_array($cmdRun->status, \App\Support\Execution\DirectWorkspace::ACTIVE, true))
            <span class="chat-command-status is-{{ $cmdActive ? 'running' : strtolower($cmdRun->status) }}" @if ($cmdActive) data-command-active data-status-url="{{ route('chat.commands.status', $cmdRun, false) }}" @endif>
                @if ($cmdActive)<span class="chat-command-spin" aria-hidden="true"></span>@endif
                <span class="chat-command-label">{{ \App\Http\Controllers\ChatCommandController::label($cmdRun->status) }}</span>
                · <a href="{{ route('stories.executions.show', [$cmdRun->story_id, $cmdRun]) }}">lihat proses</a>
            </span>
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
        @php($rRun = $message->executionRun)
        @if ($rRun && $message->id === ($latestReportIds[$rRun->id] ?? null) && $rRun->story && ! $rRun->story->isAccepted())
            <div class="chat-report-actions">
                @if ($rRun->status === 'COMPLETED' && $rRun->id === ($latestRunIds[$rRun->story_id] ?? null))
                    <form method="POST" action="{{ route('stories.executions.accept', [$rRun->story_id, $rRun]) }}">@csrf
                        <button class="button button-primary" type="submit" name="status" value="BERHASIL" onclick="return handleConfirm(event, {title: 'Terima hasil perintah?', message: 'Hasil dianggap berhasil dan masuk ke kode utama. Sistem memeriksa demo dan uji jalan dulu.', confirmLabel: 'Ya, terima'})">✓ Terima</button>
                        <button class="button button-danger" type="submit" name="status" value="GAGAL" onclick="return handleConfirm(event, {title: 'Tandai gagal?', message: 'Hasil tidak digabung ke kode utama. Anda bisa menjalankan ulang perintah ini.', confirmLabel: 'Ya, tandai gagal', danger: true})">Tandai Gagal</button>
                    </form>
                @elseif ($rRun->status === 'NEEDS_PO')
                    <form method="POST" action="{{ route('stories.executions.resume', [$rRun->story_id, $rRun]) }}">@csrf<button class="button button-primary" type="submit">Lanjutkan</button></form>
                    <form method="POST" action="{{ route('stories.executions.accept', [$rRun->story_id, $rRun]) }}">@csrf<button class="button button-danger" type="submit" name="status" value="GAGAL">Tandai Gagal</button></form>
                @elseif (in_array($rRun->status, ['FAILED', 'BLOCKED'], true) && $rRun->id === ($latestRunIds[$rRun->story_id] ?? null))
                    <form method="POST" action="{{ route('stories.executions.store', $rRun->story_id) }}">@csrf<button class="button button-primary" type="submit">↻ Jalankan ulang</button></form>
                @endif
            </div>
        @elseif ($rRun && $rRun->story && $rRun->story->isAccepted() && $message->id === ($latestReportIds[$rRun->id] ?? null))
            <p class="chat-report-decided">Keputusan Anda: <strong>{{ $rRun->story->acceptanceLabel() }}</strong>.</p>
        @endif
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
