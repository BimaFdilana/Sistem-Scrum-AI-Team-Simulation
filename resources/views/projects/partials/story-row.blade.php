@php
    $latestEstimationRun = $estimationSchemaReady ? $story->estimationRuns->first() : null;
    $agreed = $latestEstimationRun && $latestEstimationRun->story_revision === $story->revision && $latestEstimationRun->status === 'COMPLETED' && $latestEstimationRun->report_sent_at && $latestEstimationRun->consensus_points === $story->estimate_points;
    $latestExecution = $story->relationLoaded('executionRuns') ? $story->executionRuns->first() : null;

    [$estimateLabel, $estimateClass] = match (true) {
        (bool) $agreed => [$story->estimate_points.' poin', 'is-ok'],
        (bool) $story->estimate_points => ['poin belum valid', 'is-warn'],
        $latestEstimationRun?->status === 'RUNNING' => ['diskusi berjalan', 'is-run'],
        default => ['belum diestimasi', ''],
    };

    [$executionLabel, $executionClass] = match (true) {
        $story->isAccepted() => [$story->acceptanceLabel(), $story->acceptance_status === 'BERHASIL' ? 'is-ok' : 'is-bad'],
        $latestExecution?->status === 'NEEDS_PO' => ['butuh keputusan Anda', 'is-warn'],
        $latestExecution?->status === 'COMPLETED' => ['selesai, menunggu keputusan', 'is-warn'],
        $latestExecution && $latestExecution->isActive() => ['berjalan', 'is-run'],
        in_array($latestExecution?->status, ['FAILED', 'BLOCKED'], true) => ['gagal/diblokir', 'is-bad'],
        (bool) $agreed => ['siap dieksekusi', 'is-info'],
        default => ['terkunci', ''],
    };
@endphp
<details class="bk-story" role="listitem">
    <summary>
        <span class="bk-id">{{ $story->display_id }}</span>
        <span class="bk-title">{{ $story->title }}<small>{{ $story->actor?->name ?: 'Aktor belum dipilih' }}</small></span>
        <span class="bk-state"><span class="bk-chip {{ $estimateClass }}" title="Estimasi">{{ $estimateLabel }}</span><span class="bk-chip {{ $executionClass }}" title="Eksekusi">{{ $executionLabel }}</span></span>
        <span class="bk-open" aria-hidden="true">Rincian</span>
    </summary>
    <div class="bk-story-body">
        @include('projects.partials.story-detail')
    </div>
</details>
