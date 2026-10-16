@extends('layouts.workspace')

@section('content')
<div class="page-head">
    <div>
        <p class="section-kicker">PERINTAH PERBAIKAN</p>
        <h1>Perintah Perbaikan</h1>
        <p>Perintah dari PO ke Scrum Master via chat — terpisah dari Sprint Backlog. Konfirmasi di chat bersifat wajib sebelum perintah masuk ke sini.</p>
    </div>
</div>

@if (session('status'))
    <div class="notice-bar"><span class="notice-icon">✓</span><p>{{ session('status') }}</p></div>
@endif
@if ($errors->any())
    <div class="notice-bar notice-danger"><span class="notice-icon">!</span><p>{{ $errors->first() }}</p></div>
@endif

<section class="empty-panel">
    @forelse ($orders as $order)
        <article class="approval-card">
            <div class="panel-heading">
                <div>
                    <h2>#{{ $order->id }} — {{ $order->title }}</h2>
                    <p>{{ $order->project?->name ?? 'Project belum ditentukan' }} · {{ $order->created_at->format('d M Y H:i') }} · Status: <strong>{{ $order->statusLabel() }}</strong>@if($order->isAccepted()) · Keputusan PO: <strong>{{ $order->acceptanceLabel() }}</strong>@endif</p>
                </div>
                @if ($order->scope_risk)
                    <span class="soft-badge soft-badge--warn">Berisiko</span>
                @endif
            </div>
            <p>{{ \Illuminate\Support\Str::limit($order->summary, 280) }}</p>
            <div class="approval-actions">
                <a class="button button-ghost" href="{{ route('repair-orders.show', $order) }}">Detail</a>
                @if ($order->status === 'CONFIRMED')
                    <form method="POST" action="{{ route('repair-orders.run', $order) }}" style="display:inline">@csrf<button class="button button-primary" type="submit" onclick="return handleConfirm(event, {title: 'Jalankan perintah?', message: 'Jalankan perintah ini? Tim AI akan mengerjakannya via pipeline FE/BE → QA → merge.', confirmLabel: 'Ya, jalankan'})">Jalankan</button></form>
                @endif
            </div>
        </article>
    @empty
        <p>Belum ada perintah perbaikan. Perintahkan Scrum Master dari chat — misal: "perbaiki halaman login yang lambat".</p>
    @endforelse
</section>

{{ $orders->links() }}
@endsection
