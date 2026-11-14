{{-- Rilis ke GitHub: satukan hasil kerja tim dengan GitHub, lalu kirim. --}}
<div class="bk-settings">
    <section class="empty-panel rl-panel" aria-label="Rilis ke GitHub">
        <div class="panel-heading">
            <div><p class="section-kicker">RILIS KE GITHUB</p><h2>Satukan &amp; kirim sebagai satu project utuh</h2></div>
            @if ($release['connected'] ?? false)<span class="soft-badge">GitHub terhubung</span>@else<span class="soft-badge is-warn">GitHub belum terhubung</span>@endif
        </div>

        @if (! ($release['ready'] ?? false))
            <p class="field-help">{{ $release['reason'] ?? 'Status rilis belum tersedia.' }}</p>
        @else
            <p class="field-help">Repository: <code>{{ $release['remote'] }}</code> · cabang <code>main</code>.@if ($release['fetch_error']) <span class="rl-warn">{{ $release['fetch_error'] }}</span>@endif</p>

            <div class="rl-steps">
                <div class="rl-step {{ $release['behind'] === 0 ? 'is-done' : 'is-todo' }}">
                    <span class="rl-step-no">1</span>
                    <div>
                        <strong>Satukan menjadi 1 project</strong>
                        @if ($release['behind'] === 0)
                            <p>Sudah menyatu: semua perubahan di GitHub sudah ada di ScrumSpace.</p>
                        @else
                            <p>GitHub punya <strong>{{ $release['behind'] }} perubahan</strong> yang belum ada di ScrumSpace. Satukan dulu agar tidak ada yang hilang. Penggabungan dicoba di salinan terpisah; kode utama hanya berubah bila berhasil.</p>
                            <form method="POST" action="{{ route('projects.release.unify', $project) }}">@csrf
                                <button class="button button-primary" type="submit" @disabled($release['blockers'] !== [])>Satukan menjadi 1 project</button>
                            </form>
                        @endif
                        @if (session('release_conflicts'))
                            <div class="rl-conflicts" role="alert">
                                <p><strong>File yang bentrok</strong> (GitHub dan hasil kerja tim mengubah bagian yang sama):</p>
                                <ul>@foreach (session('release_conflicts') as $file)<li><code>{{ $file }}</code></li>@endforeach</ul>
                                <p>Pilih versi yang dipakai untuk bagian yang bentrok. Bagian lain dari kedua sisi tetap digabung.</p>
                                <div class="rl-actions">
                                    <form method="POST" action="{{ route('projects.release.unify', $project) }}">@csrf<input type="hidden" name="strategy" value="ours"><button class="button button-ghost" type="submit">Pakai versi ScrumSpace</button></form>
                                    <form method="POST" action="{{ route('projects.release.unify', $project) }}">@csrf<input type="hidden" name="strategy" value="theirs"><button class="button button-ghost" type="submit">Pakai versi GitHub</button></form>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="rl-step {{ $release['ahead'] === 0 ? 'is-done' : ($release['behind'] === 0 ? 'is-todo' : 'is-locked') }}">
                    <span class="rl-step-no">2</span>
                    <div>
                        <strong>Kirim ke GitHub</strong>
                        @if ($release['ahead'] === 0)
                            <p>GitHub sudah sama dengan ScrumSpace. Tidak ada yang perlu dikirim.</p>
                        @else
                            <p><strong>{{ $release['ahead'] }} commit</strong> hasil kerja tim siap dikirim ke <code>main</code> di GitHub. Sebelum mengirim, versi GitHub saat ini ditandai sebagai cadangan agar bisa dikembalikan.</p>
                            @if ($release['items'] !== [])
                                <details class="rl-items"><summary>{{ count($release['items']) }} story/perintah ikut dirilis</summary>
                                    <ul>@foreach ($release['items'] as $item)<li>{{ $item }}</li>@endforeach</ul>
                                </details>
                            @endif
                            <form method="POST" action="{{ route('projects.release.push', $project) }}">@csrf
                                <button class="button button-primary" type="submit" @disabled($release['behind'] > 0 || $release['blockers'] !== [] || ! ($release['connected'] ?? false))
                                    onclick="return handleConfirm(event, {title: 'Kirim ke GitHub?', message: '{{ $release['ahead'] }} commit dikirim ke cabang main di GitHub. Versi GitHub saat ini ditandai cadangan terlebih dulu.', confirmLabel: 'Ya, kirim'})">Kirim ke GitHub</button>
                            </form>
                            @if ($release['behind'] > 0)<p class="field-help">Aktif setelah langkah 1 selesai.</p>@endif
                            @if (! ($release['connected'] ?? false))<p class="field-help">Simpan token GitHub dulu di <a href="{{ route('settings.github.edit') }}">Koneksi GitHub</a>.</p>@endif
                        @endif
                    </div>
                </div>
            </div>

            @foreach ($release['blockers'] as $blocker)<p class="rl-warn">{{ $blocker }}</p>@endforeach

            <h3 class="rl-history-title">Riwayat rilis</h3>
            @if ($release['releases']->isEmpty())
                <p class="field-help">Belum pernah dirilis dari ScrumSpace.</p>
            @else
                <ul class="rl-history">
                    @foreach ($release['releases'] as $item)
                        <li class="is-{{ strtolower($item->status) }}">
                            <span>{{ $item->created_at->translatedFormat('d M Y, H:i') }}</span>
                            <span>{{ $item->status === 'PUSHED' ? 'Terkirim · '.$item->commit_count.' commit · cadangan '.$item->backup_tag : 'Gagal · '.$item->error }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        @endif

        <p class="field-help rl-connection">Koneksi GitHub: <a href="{{ route('settings.github.edit') }}">kelola token</a>. Token butuh izin <strong>Contents: Read and write</strong> untuk repository project ini.</p>
    </section>
</div>
