@extends('layouts.workspace', ['title' => 'GitHub', 'breadcrumb' => 'Pengaturan / GitHub'])

@section('content')
    <section class="page-heading">
        <div>
            <p class="eyebrow"><span class="eyebrow-line"></span>PENGATURAN</p>
            <h1>Integrasi GitHub</h1>
            <p class="page-subtitle">Hubungkan akun GitHub kamu dengan Personal Access Token (PAT). Website memakai token ini untuk membuat repo, fork, branch, dan merge atas namamu — tidak pernah meminta password GitHub-mu.</p>
        </div>
        <span class="count-pill">{{ $connected ? 'Terhubung' : 'Belum terhubung' }}</span>
    </section>

    @if (session('success'))
        <p class="success-banner">{{ session('success') }}</p>
    @endif
    @if (session('error'))
        <p class="form-alert">{{ session('error') }}</p>
    @endif

    @if ($connected && $githubUser)
        <section class="agent-directory-list">
            <article class="directory-row">
                <span class="agent-avatar avatar-mint">{{ mb_strtoupper(mb_substr($githubUser['login'], 0, 1)) }}</span>
                <div class="directory-main">
                    <h2>{{ '@'.$githubUser['login'] }}</h2>
                    <p>{{ $githubUser['name'] ?? 'Akun GitHub terhubung' }}</p>
                    @if ($fromEnv)
                        <p><small>Token dari <code>GITHUB_PAT</code> di <code>.env</code> (bukan dari UI).</small></p>
                    @endif
                </div>
                <span class="agent-status"><i></i>Terhubung</span>
                <div class="directory-actions">
                    @unless ($fromEnv)
                        <form method="POST" action="{{ route('settings.github.disconnect') }}">
                            @csrf
                            <button class="button" type="submit" onclick="return confirm('Putus koneksi GitHub? Token akan dihapus dari database.')">Putuskan</button>
                        </form>
                    @endunless
                </div>
            </article>
        </section>
    @endif

    <section class="form-panel" style="margin-top: 1.5rem;">
        <h2>{{ $connected ? 'Ganti token' : 'Hubungkan GitHub' }}</h2>
        <p class="page-subtitle">Belum punya token? Baca <strong>docs/github-pat-setup.md</strong> di repo untuk panduan langkah demi langkah.</p>

        <form method="POST" action="{{ route('settings.github.update') }}" id="githubPatForm">
            @csrf
            <div class="form-field">
                <label for="pat">Personal Access Token</label>
                <input type="password" id="pat" name="pat" autocomplete="off" spellcheck="false"
                       placeholder="ghp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" required>
                @error('pat')
                    <p class="form-alert">{{ $message }}</p>
                @enderror
                <p class="form-hint">Token butuh scope <code>repo</code> (full). Token disimpan terenkripsi di database — tidak pernah ditampilkan lagi setelah disimpan.</p>
            </div>

            <div class="form-actions">
                <button type="button" class="button" id="testConnectionBtn">Test Koneksi</button>
                <button type="submit" class="button button-primary">Simpan &amp; Hubungkan</button>
            </div>
            <p class="form-hint" id="testResult" aria-live="polite"></p>
        </form>
    </section>

    <section class="form-panel" style="margin-top: 1.5rem;">
        <h2>Keamanan</h2>
        <ul class="page-subtitle" style="list-style: disc; padding-left: 1.25rem;">
            <li>Token = password GitHub-mu. Jangan bagikan ke siapa pun.</li>
            <li>Kamu bisa mencabut (revoke) token kapan pun dari github.com → Settings → Developer settings.</li>
            <li>Mencabut token langsung memutus integrasi — project yang sudah terhubung tetap aman di server.</li>
        </ul>
    </section>

    <script>
        (function () {
            const btn = document.getElementById('testConnectionBtn');
            const input = document.getElementById('pat');
            const result = document.getElementById('testResult');
            btn.addEventListener('click', async () => {
                const pat = input.value.trim();
                if (!pat) { result.textContent = 'Tempel token dulu sebelum test.'; return; }
                result.textContent = 'Menghubungi GitHub…';
                btn.disabled = true;
                try {
                    const res = await fetch('{{ route('settings.github.test') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        },
                        body: JSON.stringify({ pat }),
                    });
                    const data = await res.json();
                    result.textContent = data.message || (data.ok ? 'Terhubung.' : 'Gagal.');
                    result.style.color = data.ok ? 'green' : 'red';
                } catch (e) {
                    result.textContent = 'Gagal menghubungi server.';
                    result.style.color = 'red';
                } finally {
                    btn.disabled = false;
                }
            });
        })();
    </script>
@endsection
