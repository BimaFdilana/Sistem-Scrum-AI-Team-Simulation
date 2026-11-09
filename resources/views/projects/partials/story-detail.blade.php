{{-- Rincian satu story: ringkasan, kriteria, estimasi Fibonacci, eksekusi. Dipakai di baris story papan Sprint Backlog. --}}
<div class="bk-detail">
                            <div class="bk-detail-actions"><a class="button button-ghost" href="{{ route('stories.edit', $story) }}">Edit story</a></div>


                            <div class="story-sections">

                                <div class="story-section"><p class="story-section-label">Ringkasan</p>
                                    <p><strong>Saya Ingin:</strong> {{ $story->title }}</p>
                                    <p><strong>Agar:</strong> {{ $story->sprint_purpose }}</p>
                                    @if ($agreed)
                                        @php($size = \App\Services\StoryEstimationService::category($story->estimate_points))
                                        <p class="story-estimate-final"><strong>Poin Fibonacci final · Hasil kesepakatan tim:</strong> {{ $story->estimate_points }} ({{ $size }})</p>
                                    @elseif ($story->estimate_points)
                                        <p class="estimation-stale">Poin lama belum terbukti sebagai kesepakatan tim untuk revisi ini; Eksekusi tetap terkunci.</p>
                                    @endif
                                    <a class="button button-ghost" href="{{ route('stories.discussion.show', $story) }}">Lihat Diskusi Tim</a>
                                </div>

                                <div class="story-section"><p class="story-section-label">Kriteria</p>
                                    <div class="story-criteria"><strong>Acceptance Criteria</strong><pre>{{ $story->acceptance_criteria }}</pre></div>
                                </div>

                                <div class="story-section"><p class="story-section-label">Estimasi</p>
                                    @if ($estimationSchemaReady && $sprint->status === 'PLANNING')
                                        <div class="story-estimation-panel">
                                            <form method="POST" action="{{ route('stories.estimations.store', $story) }}" data-fibonacci-form data-story-label="{{ $story->display_id }}">
                                                @csrf
                                                <button class="button button-ghost" type="submit" @disabled($latestEstimationRun?->status === 'RUNNING')>Diskusi Fibonacci via Scrum Master</button>
                                                <p class="field-help">FE, BE dan QA memberi usulan keseluruhan story, saling menanggapi, lalu secara eksplisit menyepakati poin yang sama dalam maksimal dua putaran. Tanpa kesepakatan, tidak ada poin final.</p>
                                            <p class="field-help" data-fibonacci-note role="status" hidden></p>
                                            </form>
                                            @if ($latestEstimationRun)
                                                @if ($latestEstimationRun->story_revision !== $story->revision)
                                                    <p class="estimation-stale">Run #{{ $latestEstimationRun->id }} kedaluwarsa karena User Story berubah.</p>
                                                @else
                                                    <p class="field-help">Status diskusi: <strong>{{ $latestEstimationRun->status }}</strong> · Revisi {{ $latestEstimationRun->story_revision }}</p>
                                                    @php($failedAgent = $latestEstimationRun->agentRuns->where('status', 'FAILED')->sortByDesc('id')->first())
                                                    @if ($failedAgent)
                                                        @php($failureText = match ($failedAgent->error_code) {
                                                            'PROVIDER_OVERLOADED' => 'provider sedang sibuk',
                                                            'PROVIDER_RATE_LIMITED' => 'provider sedang dibatasi (rate limit)',
                                                            'PROVIDER_TIMEOUT' => 'provider melewati batas waktu',
                                                            'PROVIDER_CONNECTION' => 'provider tidak dapat dihubungi',
                                                            'INVALID_JSON_SCHEMA' => 'jawaban agent tidak valid',
                                                            'SAFETY_REJECTION' => 'jawaban ditolak kebijakan keamanan',
                                                            'LENGTH_REJECTION' => 'jawaban agent terlalu panjang',
                                                            'EMPTY_RESPONSE' => 'jawaban agent kosong',
                                                            'STALE_OR_CANCELLED' => 'run kedaluwarsa atau dibatalkan',
                                                            'WORKER_FAILED' => 'worker diskusi berhenti',
                                                            'WORKER_TIMEOUT' => 'worker diskusi timeout',
                                                            'WORKER_STALE' => 'worker tidak mengirim heartbeat',
                                                            default => 'permintaan agent gagal',
                                                        }))
                                                        <p class="field-help"><strong>{{ strtoupper($failedAgent->profile_slug) }} · Putaran {{ $failedAgent->round }}:</strong> {{ $failureText }}. Periksa konfigurasi/koneksi, lalu gunakan Retry bila tersedia.</p>
                                                    @endif
                                                    @if ($latestEstimationRun->status === 'COMPLETED')
                                                        <a class="text-link" href="{{ route('chat.index') }}">Buka laporan di chat Scrum Master →</a>
                                                    @elseif ($latestEstimationRun->status === 'RUNNING' && $latestEstimationRun->created_at?->lte(now()->subMinutes(30)) && (int) $latestEstimationRun->requested_by === (int) auth()->id())
                                                        <form method="POST" action="{{ route('stories.estimations.cancel', [$story, $latestEstimationRun]) }}">@csrf<button class="button button-ghost" type="submit">Hentikan run lama (lebih dari 30 menit)</button></form>
                                                    @elseif ($latestEstimationRun->status === 'REPORT_PENDING')
                                                        <form method="POST" action="{{ route('stories.estimations.report', [$story, $latestEstimationRun]) }}">@csrf<button class="button button-ghost" type="submit">Coba Lagi Laporan Scrum Master</button></form>
                                                    @elseif (in_array($latestEstimationRun->status, ['FAILED', 'PARTIAL', 'NO_CONSENSUS'], true) && (int) $latestEstimationRun->requested_by === (int) auth()->id())
                                                        <form method="POST" action="{{ route('stories.estimations.retry', [$story, $latestEstimationRun]) }}">@csrf<button class="button button-ghost" type="submit">Retry diskusi (run baru)</button></form>
                                                    @endif
                                                    <p class="field-help">Lihat usulan, tanggapan, dan persetujuan asli setiap agent melalui Lihat Diskusi Tim.</p>
                                                @endif
                                            @endif
                                        </div>
                                    @else
                                        <p class="field-help">Estimasi hanya bisa dijalankan saat sprint berstatus PLANNING.</p>
                                    @endif
                                </div>

                                <div class="story-section"><p class="story-section-label">Eksekusi</p>
                                    @if ($story->isAccepted())
                                        <p class="story-estimate-final"><strong>Keputusan final PO:</strong> {{ $story->acceptanceLabel() }}</p>
                                        <button class="button button-ghost" type="button" disabled title="Story sudah diterima PO ({{ $story->acceptanceLabel() }}) dan terkunci dari eksekusi ulang">Eksekusi User Story — terkunci</button>
                                        @if ($latestExecution)
                                            <a class="button button-ghost" href="{{ route('stories.executions.show', [$story, $latestExecution]) }}">Riwayat Eksekusi</a>
                                        @endif
                                    @elseif ($agreed && $latestExecution && $latestExecution->isActive())
                                        <a class="button button-ghost" href="{{ route('stories.executions.show', [$story, $latestExecution]) }}">Lihat Eksekusi ({{ str_replace('_', ' ', $latestExecution->status) }})</a>
                                    @elseif ($agreed)
                                        <form method="POST" action="{{ route('stories.executions.store', $story) }}" style="display:inline">@csrf<button class="button button-primary" type="submit" onclick="return handleConfirm(event, {title: 'Mulai eksekusi?', message: 'Mulai eksekusi user story ini? Scrum Master akan memecah menjadi task dan meminta izin sebelum agen mengubah code.', confirmLabel: 'Ya, mulai eksekusi'})">Eksekusi User Story</button></form>
                                        @if ($latestExecution)
                                            <a class="button button-ghost" href="{{ route('stories.executions.show', [$story, $latestExecution]) }}">Riwayat Eksekusi</a>
                                        @endif
                                    @else
                                        <button class="button button-ghost" type="button" disabled title="Eksekusi terkunci sampai estimasi Fibonacci disepakati">Eksekusi User Story — terkunci</button>
                                    @endif
                                </div>
                            </div>
                        </div>
