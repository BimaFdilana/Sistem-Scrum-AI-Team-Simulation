@extends('layouts.workspace')

@section('content')
<div class="page-head">
    <div>
        <p class="section-kicker">PERINTAH PERBAIKAN</p>
        <h1>#{{ $order->id }} — {{ $order->title }}</h1>
        <p>Project: <strong>{{ $order->project?->name ?? 'belum ditentukan' }}</strong> · Status: <strong>{{ $order->statusLabel() }}</strong>@if($order->isAccepted()) · Keputusan final PO: <strong>{{ $order->acceptanceLabel() }}</strong> ({{ $order->accepted_at?->format('d M Y H:i') }})@endif</p>
    </div>
    <a class="button button-ghost" href="{{ route('repair-orders.index') }}">← Kembali</a>
</div>

@if (session('status'))
    <div class="notice-bar"><span class="notice-icon">✓</span><p>{{ session('status') }}</p></div>
@endif
@if ($errors->any())
    <div class="notice-bar notice-danger"><span class="notice-icon">!</span><p>{{ $errors->first() }}</p></div>
@endif

@if ($order->scope_risk)
    <div class="notice-bar notice-danger"><span class="notice-icon">!</span><p><strong>Perintah terdeteksi berisiko:</strong> {{ $order->risk_note }} Sistem hanya menjalankan bagian yang aman; penghapusan file ditolak otomatis.</p></div>
@endif

<section class="empty-panel">
    <div class="panel-heading"><div><p class="section-kicker">RINGKASAN SM</p><h2>Detail perintah</h2></div></div>
    <p><strong>Ringkasan:</strong> {{ $order->summary }}</p>
    <p><strong>Perintah asli PO:</strong> {{ $order->original_message }}</p>
    @if ($order->status === 'CONFIRMED' && ! $order->isAccepted())
        <form method="POST" action="{{ route('repair-orders.run', $order) }}" style="margin-top:1rem">@csrf<button class="button button-primary" type="submit" onclick="return handleConfirm(event, {title: 'Jalankan perintah?', message: 'Jalankan perintah ini? Tim AI akan mengerjakannya via pipeline FE/BE → QA → merge.', confirmLabel: 'Ya, jalankan'})">Jalankan perintah</button></form>
    @endif
    @if (in_array($order->status, ['FAILED', 'BLOCKED'], true) && ! $order->isAccepted())
        <form method="POST" action="{{ route('repair-orders.retry', $order) }}" style="margin-top:1rem">@csrf<button class="button button-primary" type="submit" onclick="return handleConfirm(event, {title: 'Coba lagi?', message: 'Kembalikan perintah ke status siap dijalankan?', confirmLabel: 'Ya, coba lagi'})">Coba lagi</button></form>
    @endif
</section>

<section class="empty-panel">
    <div class="panel-heading"><div><p class="section-kicker">RIWAYAT EKSEKUSI</p><h2>{{ $order->runs->count() }} eksekusi</h2></div></div>
    <div class="task-list">
        @forelse ($order->runs as $run)
            <div class="task-row">
                <div>
                    <strong>Eksekusi #{{ $run->id }}</strong>
                    <small>{{ str_replace('_', ' ', $run->status) }} · {{ $run->created_at->format('d M Y H:i') }}@if($run->summary) · {{ \Illuminate\Support\Str::limit($run->summary, 120) }}@endif</small>
                </div>
                <a class="button button-ghost" href="{{ route('repair-orders.executions.show', [$order, $run]) }}">Lihat</a>
            </div>
        @empty
            <p>Belum pernah dijalankan.</p>
        @endforelse
    </div>
</section>
@endsection
