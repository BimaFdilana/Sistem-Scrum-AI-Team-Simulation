@extends('layouts.workspace', ['title' => 'Diskusi Tim · '.$story->display_id, 'breadcrumb' => 'Projects / '.$story->project->name.' / Diskusi Tim'])

@section('content')
<section class="page-heading">
    <div>
        <p class="eyebrow">DISKUSI TIM · BACA SAJA</p>
        <h1>{{ $story->display_id }} — Lihat Diskusi Tim</h1>
        <p class="page-subtitle">{{ $story->title }} · Revisi story {{ $story->revision }}</p>
    </div>
    <a class="button button-ghost" href="{{ route('projects.sprint-backlog.index', $story->project_id) }}">Kembali ke Sprint Backlog</a>
</section>
@if (! $schemaReady)
    <p class="notice-bar" role="status">Diskusi tim menunggu migrasi database estimasi. Tidak ada pesan yang dibuat-buat.</p>
@else
    <section aria-labelledby="fibonacci-heading">
        <h2 id="fibonacci-heading">Diskusi Fibonacci</h2>
        <p>PO hanya dapat membaca pesan tim. Poin final memerlukan persetujuan eksplisit FE, BE, QA pada revisi yang sama dan laporan Scrum Master ke PO.</p>
        @forelse ($runs as $run)
            <article class="story-card">
                <h3>Run #{{ $run->id }} · Revisi {{ $run->story_revision }} · {{ $run->status }}</h3>
                @if ($run->story_revision !== $story->revision)<p class="estimation-stale">Kedaluwarsa: story telah berubah.</p>@endif
                @if ($run->status === 'COMPLETED' && $run->report_sent_at && $run->consensus_points)
                    <p><strong>Poin Fibonacci final:</strong> {{ $run->consensus_points }} · Laporan Scrum Master tersimpan {{ $run->report_sent_at->format('d M Y H:i') }}</p>
                @elseif ($run->status === 'NO_CONSENSUS')
                    <p>Belum sepakat setelah maksimal tiga putaran; tidak ada poin final.</p>
                @else
                    <p>Belum ada poin final yang dapat dieksekusi.</p>
                @endif
                <ol class="estimation-agent-runs">
                    @forelse ($run->messages as $message)
                        <li>
                            <strong>{{ $message->sender_slug }}</strong> → {{ $message->recipient_slug }}
                            · Putaran {{ $message->round }} · Revisi {{ $message->story_revision }}
                            · {{ $message->created_at?->format('d M Y H:i:s') }}
                            @if ($message->estimate_points) · {{ $message->estimate_points }} poin @endif
                            @if ($message->explicit_agree) · Setuju eksplisit @endif
                            <p>{{ $message->content }}</p>
                        </li>
                    @empty
                        <li>Belum ada pertukaran pesan tim pada run ini.</li>
                    @endforelse
                </ol>
                @if ($run->summary)<p><strong>Ringkasan Scrum Master:</strong> {{ $run->summary }}</p>@endif
            </article>
        @empty
            <p>Belum ada diskusi Fibonacci untuk User Story ini.</p>
        @endforelse
    </section>
    <section aria-labelledby="execution-heading">
        <h2 id="execution-heading">Eksekusi &amp; Revisi QA</h2>
        <p>Belum ada eksekusi User Story yang terverifikasi. Bagian ini hanya akan menampilkan percakapan dan bukti nyata setelah executor project terisolasi dan persetujuan PO tersedia.</p>
    </section>
@endif
@endsection
