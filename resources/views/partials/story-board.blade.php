@php
    /** @var \Illuminate\Support\Collection $boardStories */
    $showProject = $showProject ?? true;
    // Tiap kolom menampilkan 3 story teratas agar papan tidak terlalu panjang; sisanya dilipat.
    $limit = 3;

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
                    @php($visible = $grouped[$key]->take($limit))
                    @php($rest = $grouped[$key]->slice($limit))
                    @foreach ($visible as $story)
                        @include('partials.story-board-card', ['story' => $story, 'showProject' => $showProject])
                    @endforeach
                    @if ($rest->isNotEmpty())
                        <details class="board-more">
                            <summary>Tampilkan {{ $rest->count() }} lagi</summary>
                            <div class="board-more-body">
                                @foreach ($rest as $story)
                                    @include('partials.story-board-card', ['story' => $story, 'showProject' => $showProject])
                                @endforeach
                            </div>
                        </details>
                    @endif
                    @if ($grouped[$key]->isEmpty())
                        <p class="board-empty">Tidak ada story di kolom ini.</p>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</section>
