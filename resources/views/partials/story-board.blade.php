@php
    /** @var \Illuminate\Support\Collection $boardStories */
    $showProject = $showProject ?? true;

    $columnOf = function ($story) {
        $run = $story->executionRuns->first();

        if ($story->isAccepted() || ($run && $run->status === 'COMPLETED')) {
            return 'selesai';
        }
        if ($run && in_array($run->status, ['REVIEWING', 'QA_REVIEW'], true)) {
            return 'qa';
        }
        if ($run && in_array($run->status, ['QUEUED', 'BREAKDOWN', 'AWAITING_PERMISSION', 'EXECUTING', 'MERGING'], true)) {
            return 'dikerjakan';
        }
        if ($story->estimate_points || ($run && in_array($run->status, ['FAILED', 'BLOCKED'], true))) {
            return 'siap';
        }

        return 'backlog';
    };

    $columns = [
        'backlog' => ['label' => 'Backlog', 'hint' => 'belum diestimasi'],
        'siap' => ['label' => 'Siap', 'hint' => 'sudah punya poin'],
        'dikerjakan' => ['label' => 'Dikerjakan', 'hint' => 'BE/FE menulis kode'],
        'qa' => ['label' => 'QA', 'hint' => 'dinilai sekali di akhir'],
        'selesai' => ['label' => 'Selesai', 'hint' => 'diterima PO'],
    ];

    $grouped = ['backlog' => collect(), 'siap' => collect(), 'dikerjakan' => collect(), 'qa' => collect(), 'selesai' => collect()];
    foreach ($boardStories as $story) {
        $grouped[$columnOf($story)]->push($story);
    }
@endphp

<section class="board-wrap" aria-label="Papan sprint">
    <div class="board-head">
        <div>
            <p class="section-kicker">PAPAN SPRINT</p>
            <h2>Posisi setiap story</h2>
        </div>
        <div class="board-legend">
            <span>{{ $boardStories->count() }} story</span>
            <span>{{ (int) $boardStories->sum('estimate_points') }} poin</span>
        </div>
    </div>

    <div class="board">
        @foreach ($columns as $key => $meta)
            <div class="board-col board-col--{{ $key }}">
                <header class="board-col-head">
                    <span class="board-col-title">{{ $meta['label'] }}</span>
                    <span class="board-col-count">{{ $grouped[$key]->count() }}</span>
                </header>
                <p class="board-col-hint">{{ $meta['hint'] }}</p>

                <div class="board-col-body">
                    @forelse ($grouped[$key] as $story)
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
                    @empty
                        <p class="board-empty">Tidak ada story di kolom ini.</p>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>
</section>
