@extends('layouts.workspace')

@section('content')
@php
    $tasks = $run->tasks;
    $total = $tasks->count();
    $done = $tasks->whereIn('status', ['APPROVED', 'QA_PENDING'])->count();
    $running = $tasks->where('status', 'IN_PROGRESS')->count();
    $failed = in_array($run->status, ['FAILED', 'BLOCKED', 'NEEDS_PO'], true);
    $summaryText = (string) $run->summary;

    $s1 = $total > 0 || $run->status !== 'QUEUED';
    $s2 = in_array($run->status, ['EXECUTING', 'REVIEWING', 'QA_REVIEW', 'MERGING', 'COMPLETED'], true) || $done > 0;
    $s3 = $total > 0 && $done === $total;
    $s4 = $run->qa_finished_at !== null || in_array($run->status, ['MERGING', 'COMPLETED'], true);
    $s5 = $run->status === 'COMPLETED';
    $s6 = $s5 && (str_contains($summaryText, 'Demo terverifikasi') || str_contains($summaryText, 'demo health'));

    if (! $s1) { $percent = 3; }
    elseif (! $s2) { $percent = 15; }
    elseif (! $s3) { $percent = (int) round(20 + 55 * ($done / max(1, $total))); }
    elseif (! $s4) { $percent = 80; }
    elseif (! $s5) { $percent = 90; }
    else { $percent = $s6 ? 100 : 95; }

    $stepState = function (bool $complete, bool $active, bool $error = false): string {
        if ($error) { return 'is-error'; }
        if ($complete) { return 'is-done'; }
        return $active ? 'is-active' : '';
    };

    $steps = [
        ['label' => 'Breakdown', 'meta' => $total > 0 ? $total.' task' : 'menunggu SM',
         'state' => $stepState($s1, ! $s1, $failed && ! $s1)],
        ['label' => 'Izin PO', 'meta' => $s2 ? 'disetujui' : 'menunggu',
         'state' => $stepState($s2, $s1 && ! $s2)],
        ['label' => 'Eksekusi BE/FE', 'meta' => $total > 0 ? $done.'/'.$total.' task' : '—',
         'state' => $stepState($s3, $s2 && ! $s3, $failed && $s2 && ! $s3)],
        ['label' => 'QA', 'meta' => (int) $run->qa_rounds > 0 ? 'putaran '.(int) $run->qa_rounds : (($run->qa_mode ?? 'per_task') === 'batch' ? 'sekali di akhir' : 'per task'),
         'state' => $stepState($s4, $s3 && ! $s4, $failed && $s3 && ! $s4)],
        ['label' => 'Selesai', 'meta' => $s5 ? 'run ditutup' : 'menunggu',
         'state' => $stepState($s5, $s4 && ! $s5, $failed)],
        ['label' => 'Demo', 'meta' => $s6 ? 'sehat' : 'belum diverifikasi',
         'state' => $stepState($s6, $s5 && ! $s6)],
    ];

    $caption = $total > 0
        ? 'Task '.$done.' dari '.$total.($running > 0 ? ' · '.$running.' sedang dikerjakan' : '')
        : 'Menunggu Scrum Master memecah story';
    if ($run->status === 'QA_REVIEW') { $caption .= ' · QA menilai gabungan'; }
    if ($s6) { $caption .= ' · demo sehat'; }

    $projectForDemo = $run->project();
    $demo = $projectForDemo ? \App\Models\DemoServer::query()->where('project_id', $projectForDemo->id)->latest('id')->first() : null;
    $demoHealthy = $demo && $demo->health_status === 'OK';

    $timeline = $run->messages->sortByDesc('created_at')->take(8);
@endphp

<div class="page-head">
    <div>
        <p class="section-kicker">EKSEKUSI USER STORY</p>
        @if ($story->sprint_id)<p><a class="text-link" href="{{ route('chat.index', ['sprint' => $story->sprint_id]) }}">Tanya Scrum Master tentang sprint ini →</a></p>@endif
        <h1>{{ $story->display_id }} — {{ $story->effectiveTitle() }}</h1>
        <p class="muted">
            {{ $story->estimate_points }} poin · {{ $story->hasRefinement() ? 'Hasil Diskusi SM' : 'Data Asli' }}
            · <span class="exec-chip {{ $run->mode === 'direct' ? 'exec-chip--ok' : '' }}">{{ $run->mode === 'direct' ? 'direct · sekuensial' : 'worktree · paralel' }}</span>
            · <span class="exec-chip">{{ ($run->qa_mode ?? 'per_task') === 'batch' ? 'QA sekali di akhir' : 'QA per task' }}</span>
        </p>
        @if ((int) $run->qa_rounds > 0 && $run->status === 'COMPLETED')
            <div class="notice-bar"><span class="notice-icon">✓</span><p>QA meminta {{ (int) $run->qa_rounds }} putaran perbaikan; hasil akhirnya sudah dinilai ulang dan disetujui QA.</p></div>
        @endif
    </div>
    <a class="button button-ghost" href="{{ route('projects.show', $story->project) }}">← Kembali</a>
</div>

@if (session('status'))
    <div class="notice-bar"><span class="notice-icon">✓</span><p>{{ session('status') }}</p></div>
@endif
@if ($errors->any())
    <div class="notice-bar notice-danger"><span class="notice-icon">!</span><p>{{ $errors->first() }}</p></div>
@endif

<section class="exec-hero" aria-label="Progres eksekusi">
    <div class="exec-hero-head">
        <div>
            <h2>{{ $failed ? 'Eksekusi terhenti' : ($s5 ? 'Eksekusi selesai' : 'Eksekusi berjalan') }}</h2>
            <p>{{ str_replace('_', ' ', $run->status) }}{{ $run->error_code ? ' · '.$run->error_code : '' }}</p>
        </div>
        <div class="exec-percent">{{ $percent }}%</div>
    </div>

    <ol class="exec-stepper">
        @foreach ($steps as $i => $step)
            <li class="exec-step {{ $step['state'] }}">
                <span class="exec-step-dot">{{ $step['state'] === 'is-done' ? '✓' : $i + 1 }}</span>
                <span class="exec-step-label">{{ $step['label'] }}</span>
                <span class="exec-step-meta">{{ $step['meta'] }}</span>
            </li>
        @endforeach
    </ol>

    <div class="exec-bar" role="progressbar" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100">
        <div class="exec-bar-fill" style="width: {{ $percent }}%"></div>
    </div>
    <div class="exec-bar-caption">
        <span>{{ $caption }}</span>
        <span>
            @if ($demo)
                <span class="exec-chip {{ $demoHealthy ? 'exec-chip--ok' : 'exec-chip--warn' }}">
                    Demo {{ $demoHealthy ? 'sehat' : ($demo->health_status ?: 'belum dicek') }}{{ $demo->health_http_status ? ' · HTTP '.$demo->health_http_status : '' }}
                </span>
            @else
                <span class="exec-chip">Demo belum disiapkan</span>
            @endif
        </span>
    </div>
</section>

@if ($story->isAccepted())
    <div class="notice-bar"><span class="notice-icon">✓</span><p><strong>Keputusan final PO: {{ $story->acceptanceLabel() }}</strong> ({{ $story->accepted_at?->format('d M Y H:i') }}). {{ $story->isLockedFromExecution() ? 'Story terkunci.' : 'Story bisa dieksekusi ulang dari halaman project.' }}</p></div>
@elseif ($run->isTerminal())
    <div class="notice-bar"><span class="notice-icon">★</span><p><strong>Eksekusi selesai ({{ str_replace('_', ' ', $run->status) }}).</strong> Beri keputusan final sebagai Product Owner:</p></div>
    <form method="POST" action="{{ route('stories.executions.accept', [$story, $run]) }}" style="display:flex;gap:.5rem;margin-bottom:1rem">
        @csrf
        <button class="button button-primary" type="submit" name="status" value="BERHASIL" onclick="return handleConfirm(event, {title: 'Terima sebagai Berhasil?', message: 'Terima story ini sebagai BERHASIL? Story akan terkunci dari eksekusi ulang.', confirmLabel: 'Ya, terima'})">Terima sebagai Berhasil</button>
        <button class="button button-danger" type="submit" name="status" value="GAGAL" onclick="return handleConfirm(event, {title: 'Tandai sebagai Gagal?', message: 'Tandai eksekusi ini sebagai GAGAL? Hasilnya tidak digabung ke kode utama, dan story bisa dieksekusi ulang.', confirmLabel: 'Ya, tandai gagal', danger: true})">Tandai Gagal</button>
    </form>
@endif

@if ($run->status === 'AWAITING_PERMISSION')
    @include('executions.partials.permission-approval', ['run' => $run])
@elseif ($run->status === 'COMPLETED')
    <p class="discussion-banner"><strong>Eksekusi selesai.</strong> {{ $run->summary }}</p>
@elseif ($run->status === 'NEEDS_PO')
    <div class="discussion-banner discussion-banner--danger">
        <p><strong>Eksekusi berhenti, menunggu keputusan Anda.</strong> {{ $run->summary }}</p>
        <form method="POST" action="{{ route('stories.executions.resume', [$story, $run]) }}">
            @csrf
            <button class="button button-primary" type="submit">Lanjutkan eksekusi</button>
        </form>
        <form method="POST" action="{{ route('stories.executions.accept', [$story, $run]) }}">
            @csrf
            <button class="button button-danger" type="submit" name="status" value="GAGAL" onclick="return handleConfirm(event, {title: 'Tandai sebagai Gagal?', message: 'Tandai story ini sebagai GAGAL? Story akan terkunci dari eksekusi ulang.', confirmLabel: 'Ya, tandai gagal', danger: true})">Tandai Gagal</button>
        </form>
    </div>
@elseif ($run->status === 'BLOCKED')
    <p class="discussion-banner discussion-banner--danger"><strong>Eksekusi diblokir.</strong> {{ $run->summary }}</p>
@elseif ($run->status === 'FAILED')
    <p class="discussion-banner discussion-banner--danger"><strong>Eksekusi gagal.</strong> {{ $run->error_code }}</p>
@endif

@if ($run->findings->isNotEmpty())
    <section class="exec-panel" aria-label="Temuan QA">
        <h3>Temuan QA — {{ $run->findings->where('status', 'OPEN')->count() }} terbuka dari {{ $run->findings->count() }}</h3>
        <ul class="exec-permission-list">
            @foreach ($run->findings as $finding)
                <li>
                    <strong>{{ $finding->file }}{{ $finding->line ? ':'.$finding->line : '' }}</strong>
                    <span class="exec-chip {{ $finding->status === 'OPEN' ? 'exec-chip--warn' : 'exec-chip--ok' }}">{{ match ($finding->status) { 'OPEN' => 'terbuka', 'RESOLVED' => 'selesai', default => 'ditutup SM' } }}</span>
                    <span class="exec-chip">{{ $finding->severity }}</span>
                    <div>QA: {{ $finding->issue }}</div>
                    @if ($finding->dev_reason)<div>Developer ({{ $finding->dev_stance === 'disagree' ? 'tidak setuju' : 'setuju' }}): {{ $finding->dev_reason }}</div>@endif
                    @if ($finding->sm_reason)<div>SM ({{ $finding->sm_winner === 'developer' ? 'developer benar' : 'QA benar' }}): {{ $finding->sm_reason }}</div>@endif
                </li>
            @endforeach
        </ul>
    </section>
@endif

<div class="exec-grid">
    <section class="exec-panel" aria-label="Task eksekusi">
        <h3>Task dari Scrum Master — {{ $total }}</h3>

        @forelse ($tasks as $task)
            @php
                $isRunning = $task->status === 'IN_PROGRESS';
                $chipClass = match ($task->status) {
                    'APPROVED', 'QA_PENDING' => 'exec-chip--ok',
                    'REVISION', 'BLOCKED' => 'exec-chip--warn',
                    'FAILED' => 'exec-chip--bad',
                    'IN_PROGRESS', 'IN_REVIEW' => 'exec-chip--running',
                    default => '',
                };
            @endphp
            <article class="exec-task {{ $isRunning ? 'is-running' : '' }}">
                <div class="exec-task-head">
                    <div>
                        <div class="exec-task-title">
                            @if ($isRunning)<span class="exec-dot-live"></span>@endif
                            {{ $task->title }}
                        </div>
                        <div class="exec-task-sub">{{ $task->assigneeName() }}{{ $task->revision_count ? ' · revisi '.$task->revision_count : '' }}</div>
                    </div>
                    <div class="exec-badges">
                        @if ($task->executor)
                            <span class="exec-chip exec-chip--{{ $task->executor === 'opencode' ? 'opencode' : 'hermes' }}">{{ $task->executor === 'opencode' ? 'OpenCode' : 'Hermes' }}</span>
                        @endif
                        @if ($task->last_error === 'OPENCODE_FALLBACK')
                            <span class="exec-chip exec-chip--info" title="OpenCode gagal; task dilanjutkan worker Hermes tanpa mengubah hasil akhir">dialihkan ke Hermes</span>
                        @endif
                        <span class="exec-chip {{ $chipClass }}">{{ str_replace('_', ' ', $task->status) }}</span>
                    </div>
                </div>
                <p class="exec-task-desc">{{ $task->description }}</p>

                @if ($task->qa_feedback && in_array($task->status, ['REVISION', 'BLOCKED'], true))
                    <p class="qa-feedback"><strong>Feedback QA:</strong> {{ $task->qa_feedback }}</p>
                @endif
                @if ($task->last_error && $task->last_error !== 'OPENCODE_FALLBACK')
                    <p class="qa-feedback"><strong>Error:</strong> {{ $task->last_error }}</p>
                @endif
                @if ($task->diff_snapshot)
                    <details style="margin-top:.5rem">
                        <summary>Diff task ({{ strlen($task->diff_snapshot) }} karakter)</summary>
                        <pre style="white-space:pre-wrap;word-break:break-word;font-size:.78rem">{{ $task->diff_snapshot }}</pre>
                    </details>
                @endif
            </article>
        @empty
            <p class="exec-empty">Scrum Master sedang memecah story menjadi task…</p>
        @endforelse
    </section>

    <aside>
        <section class="exec-panel" aria-label="Panel QA">
            <h3>Penilaian QA</h3>
            @if ($run->qa_started_at)
                <p class="exec-empty">Mulai {{ $run->qa_started_at->format('H:i') }}{{ $run->qa_finished_at ? ' · selesai '.$run->qa_finished_at->format('H:i') : '' }} · {{ (int) $run->qa_rounds }} putaran perbaikan</p>
            @else
                <p class="exec-empty">QA menilai setelah seluruh task BE/FE selesai.</p>
            @endif

            @php $findings = $tasks->filter(fn ($t) => filled($t->qa_feedback) && in_array($t->status, ['REVISION', 'BLOCKED'], true)); @endphp
            @forelse ($findings as $task)
                <div class="exec-finding">
                    <strong>{{ $task->assigneeName() }}</strong> — {{ $task->title }}
                    <div>{!! nl2br(e($task->qa_feedback)) !!}</div>
                </div>
            @empty
                <p class="exec-empty">Belum ada temuan QA.</p>
            @endforelse
        </section>

        <section class="exec-panel" aria-label="Timeline">
            <h3>Timeline</h3>
            <ol class="exec-timeline">
                @forelse ($timeline as $message)
                    <li>
                        <time>{{ $message->created_at->format('d M H:i') }}</time>
                        <strong>{{ str_replace(['-', '_'], ' ', $message->sender_slug) }} → {{ str_replace(['-', '_'], ' ', $message->recipient_slug) }}</strong>
                        <p>{{ \Illuminate\Support\Str::limit($message->content, 180) }}</p>
                    </li>
                @empty
                    <li><time>—</time><strong>Belum ada aktivitas</strong></li>
                @endforelse
            </ol>
        </section>
    </aside>
</div>
@endsection
