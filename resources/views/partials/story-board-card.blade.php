{{-- Kartu story pada papan sprint. --}}
    @php
        $run = $story->executionRuns->first();
        $total = $run ? $run->tasks->count() : 0;
        $done = $run ? $run->tasks->whereIn('status', ['APPROVED', 'QA_PENDING'])->count() : 0;
        $pct = $total > 0 ? (int) round($done / $total * 100) : 0;
        $locked = $story->isLockedFromExecution();
        $link = $run && $story->project
            ? route('stories.executions.show', [$story, $run])
            : route('stories.edit', $story);
    @endphp
    <a class="board-card {{ $locked ? 'is-locked' : '' }}" href="{{ $link }}">
        <div class="board-card-top">
            <span class="board-id">{{ $story->display_id }}</span>
            @if ($story->estimate_points)
                <span class="board-points">{{ $story->estimate_points }} pt</span>
            @endif
        </div>
        <strong class="board-title">{{ $story->effectiveTitle() }}</strong>
        @if ($showProject && $story->project)
            <span class="board-project">{{ $story->project->name }}</span>
        @endif
        <div class="board-card-meta">
            @if ($story->actor)
                <span class="board-avatar" title="{{ $story->actor->name }}">{{ mb_strtoupper(mb_substr($story->actor->name, 0, 1)) }}</span>
                <span class="board-actor">{{ $story->actor->name }}</span>
            @else
                <span class="board-actor board-actor--empty">aktor belum dipilih</span>
            @endif
        </div>

        @if ($locked)
            <span class="board-badge board-badge--done">terkunci · diterima</span>
        @elseif ($run)
            <div class="board-progress">
                <div class="board-progress-bar"><span style="width: {{ $pct }}%"></span></div>
                <small>{{ $done }}/{{ $total }} task · {{ str_replace('_', ' ', $run->status) }}</small>
            </div>
        @else
            <span class="board-badge">belum dieksekusi</span>
        @endif
    </a>
