{{-- Satu pesan chat SM: obrolan, perintah untuk tim, atau kartu laporan. --}}
@if ($message->kind === 'command' && $message->role === 'user')
    @php
        $cmd = $message->chatCommand;
        $cmdRun = $cmd && $cmd->story_id ? \App\Models\ExecutionRun::query()->where('story_id', $cmd->story_id)->latest('id')->first() : $message->executionRun;
        $cmdActive = $cmdRun && in_array($cmdRun->status, \App\Support\Execution\DirectWorkspace::ACTIVE, true);
        $understanding = $cmd && $cmd->phase === \App\Models\ChatCommand::UNDERSTANDING;
    @endphp
    <article class="chat-message chat-message-user chat-message-command">
        <span class="chat-message-label">Anda · perintah untuk tim<time datetime="{{ $message->created_at->toIso8601String() }}">{{ $message->created_at->translatedFormat('d M, H:i') }}</time></span>
        <p>{{ $message->content }}</p>
        @if ($understanding || ($cmd && ! $cmdRun && $cmd->phase !== \App\Models\ChatCommand::CONFIRMED))
            <span class="chat-command-status is-{{ $understanding ? 'running' : strtolower($cmd->phase) }}" @if ($understanding) data-command-active data-status-url="{{ route('chat.commands.phase', $cmd, false) }}" @endif>
                @if ($understanding)<span class="chat-command-spin" aria-hidden="true"></span>@endif
                <span class="chat-command-label">{{ $cmd->phaseLabel() }}</span>
            </span>
        @elseif ($cmdRun)
            <span class="chat-command-status is-{{ $cmdActive ? 'running' : strtolower($cmdRun->status) }}" @if ($cmdActive) data-command-active data-status-url="{{ route('chat.commands.status', $cmdRun, false) }}" @endif>
                @if ($cmdActive)<span class="chat-command-spin" aria-hidden="true"></span>@endif
                <span class="chat-command-label">{{ \App\Http\Controllers\ChatCommandController::label($cmdRun->status) }}</span>
                · <a href="{{ route('stories.executions.show', [$cmdRun->story_id, $cmdRun]) }}">lihat proses</a>
            </span>
        @endif
    </article>
@elseif ($message->kind === 'command_plan' && $message->chatCommand)
    @php
        $plan = $message->chatCommand;
        $isLatestPlan = $message->id === ($latestPlanIds[$plan->id] ?? null);
    @endphp
    <article class="chat-plan is-{{ strtolower($plan->phase) }}" id="rencana-{{ $plan->id }}">
        <header>
            <span class="chat-report-tag">RENCANA SCRUM MASTER{{ $plan->partLabel() ? ' · '.mb_strtoupper($plan->partLabel()) : '' }}</span>
            <span class="chat-plan-state">{{ $isLatestPlan ? $plan->phaseLabel() : 'Rumusan lama' }}</span>
        </header>
        @if (! $plan->partLabel() && $plan->phase !== \App\Models\ChatCommand::NOT_UNDERSTOOD)<p class="chat-plan-intro">{{ $message->content }}</p>@endif
        @if ($isLatestPlan && $plan->phase === \App\Models\ChatCommand::AWAITING)
            <form method="POST" action="{{ route('chat.commands.confirm', $plan) }}" class="chat-plan-form">
                @csrf
                <label for="plan-title-{{ $plan->id }}">Pekerjaan</label>
                <input id="plan-title-{{ $plan->id }}" name="title" value="{{ $plan->title }}" maxlength="150" required>
                <label for="plan-criteria-{{ $plan->id }}">Kriteria selesai <small>(satu kriteria per baris; ubah bila perlu)</small></label>
                <textarea id="plan-criteria-{{ $plan->id }}" name="criteria" rows="{{ max(3, count($plan->criteria ?? []) + 1) }}" required>{{ implode("\n", $plan->criteria ?? []) }}</textarea>
                <div class="chat-plan-actions">
                    <button class="button button-primary" type="submit">✓ Sesuai, kerjakan</button>
                    <button class="button button-ghost" type="submit" formaction="{{ route('chat.commands.cancel', $plan) }}" formnovalidate>Batalkan</button>
                </div>
            </form>
        @elseif ($isLatestPlan && $plan->phase === \App\Models\ChatCommand::NOT_UNDERSTOOD)
            <p>{{ $message->content }}</p>
            <div class="chat-plan-actions">
                <form method="POST" action="{{ route('chat.commands.rethink', $plan) }}">@csrf<button class="button button-primary" type="submit">↻ Coba rumuskan lagi</button></form>
                <form method="POST" action="{{ route('chat.commands.cancel', $plan) }}">@csrf<button class="button button-ghost" type="submit">Batalkan</button></form>
            </div>
        @else
            <strong class="chat-plan-title">{{ $plan->title }}</strong>
            @if (! empty($plan->criteria))<ol class="chat-plan-criteria">@foreach ($plan->criteria as $criterion)<li>{{ $criterion }}</li>@endforeach</ol>@endif
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
        @php $rRun = $message->executionRun; @endphp
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
                    @php
                        $failKind = \App\Support\Chat\ChatCommandReporter::failureKind($rRun);
                        $failCmd = \App\Models\ChatCommand::query()->where('story_id', $rRun->story_id)->latest('id')->first();
                    @endphp
                    @if (in_array($failKind, ['transient', 'setup'], true) || $failCmd === null)
                        <form method="POST" action="{{ route('stories.executions.store', $rRun->story_id) }}">@csrf<button class="button button-primary" type="submit">↻ Jalankan ulang</button></form>
                    @else
                        <form method="POST" action="{{ route('chat.commands.rethink', $failCmd) }}">@csrf<button class="button button-primary" type="submit">✎ Rumuskan ulang</button></form>
                    @endif
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
