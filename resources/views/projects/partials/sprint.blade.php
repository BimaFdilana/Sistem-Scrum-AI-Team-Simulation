@php
    $acceptedCount = $sprint->stories->filter->isAccepted()->count();
    $pointTotal = (int) $sprint->stories->sum('estimate_points');
    $statusClass = match ($sprint->status) { 'ACTIVE' => 'is-ok', 'COMPLETED' => '', default => 'is-warn' };
@endphp
<details class="bk-sprint" id="sprint-{{ $sprint->id }}" @if (in_array($sprint->status, ['ACTIVE', 'PLANNING'], true)) open @endif>
    <summary>
        <span class="bk-chevron" aria-hidden="true">›</span>
        <span class="bk-sprint-title">
            <strong>{{ $sprint->name }}</strong>
            <small>{{ $sprint->goal ? \Illuminate\Support\Str::limit($sprint->goal, 90) : 'Sprint Goal belum dirumuskan' }}</small>
        </span>
        <span class="bk-sprint-meta">{{ $sprint->stories_count }} story · {{ $pointTotal }} poin · {{ $acceptedCount }} diterima</span>
        <span class="bk-chip {{ $statusClass }}">{{ $sprint->status }}</span>
    </summary>

    <div class="bk-sprint-body">
        <div class="sprint-tools">
            <details class="sprint-rename">
                <summary class="button button-ghost">Ubah nama sprint</summary>
                <form method="POST" action="{{ route('sprints.update', $sprint) }}" class="sprint-rename-form">
                    @csrf @method('PATCH')
                    <label class="sr-only" for="sprint-name-{{ $sprint->id }}">Nama sprint</label>
                    <input id="sprint-name-{{ $sprint->id }}" type="text" name="name" value="{{ $sprint->name }}" maxlength="120" required>
                    <button class="button button-primary" type="submit">Simpan</button>
                </form>
            </details>
            <a class="button button-ghost" href="{{ route('chat.index', ['sprint' => $sprint->id]) }}">Chat dengan Scrum Master</a>
            @if ($sprint->status === 'ACTIVE')
                <form method="POST" action="{{ route('sprints.reopen', $sprint) }}">
                    @csrf
                    <button class="button button-ghost" type="submit" onclick="return handleConfirm(event, {title: 'Buka kunci sprint?', message: 'Sprint kembali ke Perencanaan: Anda bisa menambah atau mengubah story dan meminta estimasi ulang. Story yang sudah diterima tetap terkunci. Setelah selesai, setujui lagi lalu klik Mulai Sprint.', confirmLabel: 'Ya, buka kunci'})">🔓 Buka kunci sprint</button>
                </form>
            @endif
        </div>
        @if ($sprint->status === 'ACTIVE')
            <p class="sprint-lock-note">Sprint ini terkunci karena sedang berjalan. Untuk menambah story atau estimasi ulang, klik <strong>Buka kunci sprint</strong>.</p>
        @endif
        @if ($sprint->goal)
            <div class="sprint-goal-box"><strong>Sprint Goal · {{ $sprint->goal_source === 'AI_DRAFT' ? 'draf Scrum Master AI' : 'goal lama, perlu dirumuskan ulang oleh AI' }}</strong><p>{{ $sprint->goal }}</p></div>
        @endif

        @if ($sprint->stories->isNotEmpty())
            <div class="bk-stories" role="list">
                @foreach ($sprint->stories as $story)
                    @include('projects.partials.story-row', ['story' => $story, 'sprint' => $sprint])
                @endforeach
            </div>
        @else
            <p class="empty-inline">Belum ada User Story di sprint ini.</p>
        @endif

        @if ($sprint->status === 'PLANNING')
            @include('projects.partials.add-story')
            @include('projects.partials.sprint-actions')
        @endif
    </div>
</details>
