<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Antigravity CLI (agy)
    |--------------------------------------------------------------------------
    | Satu mesin AI untuk semua peran (SM, BE, FE, QA): chat, estimasi,
    | breakdown, review QA, dan eksekusi task. Dijalankan sebagai proses
    | `agy -p` non-interaktif. Di VPS, worker www-data memanggil wrapper
    | bin/agy-as-sidik karena login akun Google tersimpan di home sidik.
    */
    'antigravity' => [
        'enabled' => (bool) env('ANTIGRAVITY_ENABLED', true),
        // Default: user sidik (pemilik login) memanggil agy langsung; user lain
        // (www-data di VPS) lewat wrapper sudo agar memakai login sidik.
        'binary' => env('ANTIGRAVITY_BINARY', (function_exists('posix_geteuid') && (posix_getpwuid(posix_geteuid())['name'] ?? '') === 'sidik')
            ? '/home/sidik/.local/bin/agy'
            : base_path('bin/agy-as-sidik')),
        // Folder profil peran global (dibaca agy milik user yang login).
        'agents_dir' => env('ANTIGRAVITY_AGENTS_DIR', '/home/sidik/.gemini/config/agents'),
        // Folder kerja kosong untuk panggilan chat (tanpa akses project).
        'sandbox_dir' => env('ANTIGRAVITY_SANDBOX_DIR', storage_path('app/private/agy-sandbox')),
        // Batas waktu satu panggilan chat dan satu task developer (detik).
        // Task harus < timeout job (1800) agar gagal bersih sebelum worker
        // membunuh job.
        'chat_timeout' => (int) env('ANTIGRAVITY_CHAT_TIMEOUT', 180),
        'task_timeout' => (int) env('ANTIGRAVITY_TASK_TIMEOUT', 1500),
        // Percobaan ulang untuk error sementara pada model yang sama.
        'chat_attempts' => (int) env('ANTIGRAVITY_CHAT_ATTEMPTS', 2),
        // Cache daftar model dan status login (detik).
        'models_cache' => (int) env('ANTIGRAVITY_MODELS_CACHE', 600),
        // File token login agy milik user sidik. Hilang = sudah Keluar.
        'token_file' => env('ANTIGRAVITY_TOKEN_FILE', '/home/sidik/.gemini/antigravity-cli/antigravity-oauth-token'),
        // Detik cache sisa kuota akun (/quota). Halaman AI Model memintanya tiap menit.
        'quota_cache' => (int) env('ANTIGRAVITY_QUOTA_CACHE', 60),
        // Profil agy untuk peran developer yang mengedit worktree. Sumber
        // profil ada di resources/antigravity/agents (php artisan
        // antigravity:sync-agents menyalinnya ke agents_dir).
        'role_agents' => [
            'backend-developer' => 'scrumspace-backend',
            'frontend-developer' => 'scrumspace-frontend',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pemetaan model -> provider untuk pencatatan pemakaian token
    |--------------------------------------------------------------------------
    | Dipakai AiUsageRecorder untuk mengelompokkan pemakaian per penyedia
    | model (Gemini, Claude, GPT-OSS). BUKAN untuk memilih model.
    */
    'model_providers' => [
        'google' => ['label' => 'Google', 'id' => ['gemini'], 'owned_by' => ['google', 'antigravity']],
        'codex' => ['label' => 'OpenAI', 'id' => ['gpt', 'codex'], 'owned_by' => ['openai']],
        'anthropic' => ['label' => 'Anthropic', 'id' => ['claude'], 'owned_by' => ['anthropic']],
        'deepseek' => ['label' => 'DeepSeek', 'id' => ['deepseek'], 'owned_by' => ['deepseek']],
    ],
];
