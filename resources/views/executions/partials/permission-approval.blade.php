@php
    // Ambil permintaan yang benar-benar masih PENDING (bisa ada riwayat lama).
    $pendingPermission = \App\Models\PermissionRequest::query()
        ->where('execution_run_id', $run->id)
        ->where('status', 'PENDING')
        ->latest('id')
        ->first();
@endphp

@if ($run->status === 'AWAITING_PERMISSION' && $pendingPermission)
    <section class="exec-panel exec-permission" aria-label="Persetujuan izin">
        <div class="exec-hero-head" style="margin-bottom:10px">
            <div>
                <h3 style="margin:0 0 4px">Minta izin mengubah kode</h3>
                <p class="exec-empty" style="margin:0">
                    {{ $pendingPermission->title }} · diminta {{ $pendingPermission->created_at->format('d M H:i') }}
                    · {{ $run->tasks->count() }} task
                </p>
            </div>
            <span class="exec-chip exec-chip--warn">menunggu keputusan PO</span>
        </div>

        @if (is_array($pendingPermission->payload['tasks'] ?? null))
            <ul class="exec-permission-list">
                @foreach ($pendingPermission->payload['tasks'] as $task)
                    <li>
                        <strong>{{ $task['title'] ?? 'Task' }}</strong>
                        <span class="exec-chip">{{ $task['assignee'] ?? 'agent' }}</span>
                        @if (! empty($task['files']))
                            <small>{{ implode(', ', (array) $task['files']) }}</small>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:12px">
            <form method="POST" action="{{ route('accepted.approve', $pendingPermission) }}">
                @csrf
                <button class="button button-primary" type="submit">Setujui &amp; mulai kerjakan</button>
            </form>
            <form method="POST" action="{{ route('accepted.reject', $pendingPermission) }}">
                @csrf
                <button class="button button-danger" type="submit" onclick="return handleConfirm(event, {title: 'Tolak izin?', message: 'Eksekusi dibatalkan dan story tidak dijalankan.', confirmLabel: 'Ya, tolak', danger: true})">Tolak</button>
            </form>
            <form method="POST" action="{{ route('accepted.cancel', $pendingPermission) }}">
                @csrf
                <button class="button button-ghost" type="submit">Batalkan &amp; revisi nanti</button>
            </form>
        </div>
    </section>
@elseif ($run->status === 'AWAITING_PERMISSION')
    <p class="discussion-banner">Menunggu permintaan izin dari Scrum Master…</p>
@endif
