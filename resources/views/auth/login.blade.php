<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Masuk · AI Scrum Agent Workspace</title>
    <link rel="stylesheet" href="{{ asset('css/workspace.css') }}">
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
                    <input id="password" name="password" type="password" autocomplete="current-password" required placeholder="Masukkan kata sandi">
                    @error('password')<small class="field-error">{{ $message }}</small>@enderror

                    <button class="button button-primary button-full" type="submit">Masuk ke workspace <span aria-hidden="true">→</span></button>
                </form>

                <p class="login-security"><span class="lock-symbol" aria-hidden="true">●</span>Registrasi publik dinonaktifkan. Akses hanya untuk Product Owner.</p>
            </div>
            <div class="login-meta"><span>AI Scrum Agent Workspace</span><span>Internal preview</span></div>
        </section>
    </main>
</body>
</html>
