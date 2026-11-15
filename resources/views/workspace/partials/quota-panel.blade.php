{{-- Kuota akun Google (Antigravity): sisa dan waktu reset per jendela. Data dari gateway; tanpa biaya model. --}}
@php
    $accounts = $quotaSnapshot['accounts'] ?? [];
    $exhaustedState = \App\Support\Ai\AiQuota::current();
@endphp
<article class="ap-card ap-quota" id="kuota-akun" aria-label="Kuota akun Google">
    <div class="ap-card-head">
        <div>
            <p class="section-kicker">KUOTA</p>
            <h2>Kuota akun Google</h2>
        </div>
        <form method="POST" action="{{ route('ai-providers.quota.refresh') }}">@csrf<button class="button button-ghost" type="submit">↻ Perbarui</button></form>
    </div>
    @error('quota')<p class="form-alert">{{ $message }}</p>@enderror

    @if ($quotaSnapshot === null || $accounts === [])
        <p class="ap-note">Belum ada akun Google (Antigravity) yang terhubung, atau gateway belum dapat dihubungi. Kuota hanya tampil untuk akun Google; model dari akun API key tidak punya data kuota.</p>
    @else
        @foreach ($accounts as $account)
            <div class="ap-quota-account">
                <div class="ap-quota-head">
                    <strong>{{ $account['label'] }}</strong>
                    <span class="soft-badge">{{ \App\Support\Ai\AntigravityQuota::planLabel($account['plan']) }}@if ($account['tier']) · {{ $account['tier'] }}@endif</span>
                    @if ($account['disabled'])<span class="soft-badge is-warn">dinonaktifkan</span>@endif
                </div>
                @if ($account['disabled'])
                    <p class="ap-note">Akun dinonaktifkan di gateway, jadi kuotanya tidak dibaca.</p>
                @elseif ($account['windows'] === [])
                    <p class="ap-note">{{ $account['error'] ?? 'Google belum mengembalikan data kuota untuk akun ini.' }}</p>
                @else
                    @foreach ($account['windows'] as $w)
                        @php
                            $pct = (int) round($w['remaining'] * 100);
                            $level = $w['remaining'] <= 0.001 ? 'is-empty' : ($w['remaining'] < 0.15 ? 'is-low' : '');
                        @endphp
                        <div class="ap-quota-row {{ $level }}">
                            <div class="ap-quota-line">
                                <span>{{ $w['group_label'] }} · <strong>{{ $w['window_label'] }}</strong></span>
                                <span>sisa <strong>{{ $pct }}%</strong></span>
                            </div>
                            <div class="ap-quota-bar" role="progressbar" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100" aria-label="Sisa kuota {{ $w['group_label'] }} {{ $w['window_label'] }}"><span style="width: {{ $pct }}%"></span></div>
                            <p class="ap-quota-reset">
                                @if ($w['reset_fixed'])
                                    Reset {{ \App\Support\Ai\AiQuota::resetLabel($w['reset_at']) }} · <strong data-quota-countdown data-reset-at="{{ $w['reset_at'] }}">{{ \App\Support\Ai\AiQuota::remaining($w['reset_at']) }}</strong>
                                @else
                                    Kuota masih penuh, jadi belum ada jadwal reset. Waktu reset baru tampil setelah kuota terpakai.
                                @endif
                            </p>
                        </div>
                    @endforeach
                    @if (collect($account['windows'])->every(fn ($w) => $w['window'] === 'weekly'))
                        <p class="ap-note">Akun ini hanya punya batas <strong>mingguan</strong>. Jendela 5 jam tidak disediakan Google untuk tingkat akun ini{{ $account['plan'] === 'free' ? ' (tingkat gratis)' : '' }}.</p>
                    @endif
                @endif
            </div>
        @endforeach
        <p class="form-hint">Dibaca {{ \Illuminate\Support\Carbon::parse($quotaSnapshot['fetched_at'])->timezone('Asia/Jakarta')->format('H:i') }} WIB dan diperbarui otomatis tiap 15 menit saat halaman dibuka.@if ($exhaustedState) Saat ini kuota yang dipakai sedang habis; lihat banner di atas untuk perkiraan pulih.@endif</p>
    @endif
</article>
