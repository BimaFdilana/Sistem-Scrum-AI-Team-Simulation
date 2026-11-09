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
