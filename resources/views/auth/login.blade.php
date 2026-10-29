<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <title>Masuk · AI Scrum Agent Workspace</title>
    <script>
        (function () {
            try {
                var t = localStorage.getItem('scrumspace-theme');
                if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t);
            } catch (e) {}
        })();
    </script>
    <link rel="stylesheet" href="{{ asset('css/workspace.css') }}?v=2">
</head>
<body class="login-body">
    <main class="login-layout">
        <section class="login-story">
            <a class="brand brand-login" href="{{ route('login') }}">
                <span class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></span>
                <span class="brand-copy"><strong>scrumspace</strong><small>AI agent workspace</small></span>
            </a>
            <div class="login-message">
                <p class="eyebrow"><span class="eyebrow-line"></span>CONTROL CENTER FOR HUMAN-LED SCRUM</p>
                <h1>Ritme tim.<br><span>Satu ruang kerja.</span></h1>
                <p>Jalankan workflow Scrum bersama agent AI, dengan keputusan penting tetap berada di tangan Product Owner.</p>
                <div class="login-flow" aria-label="Alur kerja">
                    <span>Plan</span><i></i><span>Build</span><i></i><span>Verify</span><i></i><span>Accept</span>
                </div>
            </div>
            <div class="login-footnote"><span class="status-dot status-ready"></span>Private workspace · Login admin saja</div>
        </section>

        <section class="login-panel-wrap">
            <div class="login-panel">
                <div class="login-panel-heading">
                    <span class="panel-kicker">PRODUCT OWNER ACCESS</span>
                    <h2>Masuk ke workspace</h2>
                    <p>Gunakan akun admin yang disiapkan untuk workspace ini.</p>
                </div>

                @if ($errors->any())
                    <div class="form-alert" role="alert">{{ $errors->first() }}</div>
                @endif

                <form method="POST" action="{{ route('login.store') }}" class="login-form">
                    @csrf
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus placeholder="nama@organisasi.id">
                    @error('email')<small class="field-error">{{ $message }}</small>@enderror

                    <div class="password-label"><label for="password">Kata sandi</label><span>Login privat</span></div>
                    <div class="password-field" style="position:relative">
                        <input id="password" name="password" type="password" autocomplete="current-password" required placeholder="Masukkan kata sandi" style="padding-right:44px">
                        <button type="button" class="password-toggle" data-password-toggle aria-label="Tampilkan kata sandi" aria-pressed="false" title="Tampilkan kata sandi" style="position:absolute;right:6px;top:50%;transform:translateY(-50%);width:32px;height:32px;display:grid;place-items:center;padding:0;border:1px solid transparent;border-radius:7px;background:transparent;color:#9aa9ad;cursor:pointer">
                            <svg class="icon-eye" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;display:block"><path d="M2.036 12.322a1.012 1.012 0 0 1 0-1.042C3.423 7.51 7.254 4.5 12 4.5c4.746 0 8.577 3.01 9.964 6.822a1.012 1.012 0 0 1 0 1.042C20.577 16.49 16.746 19.5 12 19.5c-4.746 0-8.577-3.01-9.964-6.822Z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="icon-eye-off icon-hidden" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;display:none"><path d="M2.036 12.322a1.012 1.012 0 0 1 0-1.042C3.423 7.51 7.254 4.5 12 4.5c4.746 0 8.577 3.01 9.964 6.822a1.012 1.012 0 0 1 0 1.042C20.577 16.49 16.746 19.5 12 19.5c-4.746 0-8.577-3.01-9.964-6.822Z"/><circle cx="12" cy="12" r="3"/><line x1="3" y1="3" x2="21" y2="21"/></svg>
                        </button>
                    </div>
                    @error('password')<small class="field-error">{{ $message }}</small>@enderror

                    <button class="button button-primary button-full" type="submit">Masuk ke workspace <span aria-hidden="true">→</span></button>
                </form>

                <p class="login-security"><span class="lock-symbol" aria-hidden="true">●</span>Registrasi publik dinonaktifkan. Akses hanya untuk Product Owner.</p>
            </div>
            <div class="login-meta"><span>AI Scrum Agent Workspace</span><span>Internal preview</span></div>
        </section>
    </main>
    <script>
        (function () {
            var btn = document.querySelector('[data-password-toggle]');
            var input = document.getElementById('password');
            if (!btn || !input) return;
            btn.addEventListener('click', function () {
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                btn.setAttribute('aria-pressed', show ? 'true' : 'false');
                btn.setAttribute('aria-label', show ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi');
                btn.setAttribute('title', show ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi');
                btn.querySelector('.icon-eye').classList.toggle('icon-hidden', show);
                btn.querySelector('.icon-eye-off').classList.toggle('icon-hidden', !show);
                btn.querySelector('.icon-eye').style.display = show ? 'none' : 'block';
                btn.querySelector('.icon-eye-off').style.display = show ? 'block' : 'none';
                input.focus();
            });
        })();
    </script>
</body>
</html>
