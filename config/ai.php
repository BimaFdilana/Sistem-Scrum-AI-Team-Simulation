<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CLIProxyAPI gateway
    |--------------------------------------------------------------------------
    | Local OpenAI-compatible gateway (CLIProxyAPI) that wraps OAuth-based
    | provider accounts (Antigravity/Google, Codex/ChatGPT, Claude, ...).
    | Replaces the direct Hermes-only setup once accounts are connected.
    */
    'cliproxy' => [
        'base_url' => env('CLIPROXY_BASE_URL', 'http://127.0.0.1:8317'),
        'management_key' => env('CLIPROXY_MANAGEMENT_KEY'),
        'api_key' => env('CLIPROXY_API_KEY'),
        'timeout' => (int) env('CLIPROXY_TIMEOUT', 15),
        // Jalur agen (FE/BE/QA/SM) lewat akun OAuth CLIProxyAPI. Dimatikan
        // saat testing (phpunit.xml) agar tidak bocor ke gateway asli.
        'agents_enabled' => env('CLIPROXY_AGENTS_ENABLED', true),
        // Ketahanan chat: berapa kali mencoba MODEL YANG SAMA (backoff
        // 1s,2s,4s…). Aturan tanpa-default: tidak ada model cadangan
        // yang dicoba — bila model bermasalah, alur berhenti dengan
        // pesan MODEL_PROBLEMATIC dan PO yang memilih penggantinya.
        'chat_attempts' => (int) env('CLIPROXY_CHAT_ATTEMPTS', 3),
        // Budget waktu absolut (detik) untuk seluruh loop retry chatResilient.
        // Mencegah fatal error PHP bila upstream hang (harus < set_time_limit controller).
        'chat_deadline' => (int) env('CLIPROXY_CHAT_DEADLINE', 240),
    ],

    /*--------------------------------------------------------------------------
    | Driver eksekusi task
    |--------------------------------------------------------------------------
    | 'internal' = loop tool lama (AgentToolRunner, lewat gateway).
    | 'hermes'   = adapter Hermes CLI (HermesTaskRunner): satu query
    |              non-interaktif per task, bekerja langsung di worktree.
    | Gerbang permission, QA, merge, dan demo tidak berubah di kedua driver.
    | Loop lama dipertahankan sebagai fallback (ubah EXECUTION_DRIVER).
    */
    'execution_driver' => env('EXECUTION_DRIVER', 'internal'),

    'hermes' => [
        // Biner Hermes. Di VPS, worker www-data memanggil path absolut
        // /home/sidik/.local/bin/hermes lewat env ini (PATH worker tidak
        // mencakup bin user sidik).
        'binary' => env('HERMES_BINARY', 'hermes'),
        // Legacy, TIDAK lagi dikonsultasikan sebagai cadangan model
        // (aturan tanpa-default: model hanya dari pilihan PO di menu
        // Konfigurasi Agent, lewat StrictModelResolver). Kunci
        // dipertahankan agar env lama tidak error, tapi nilainya
        // tidak pernah dipakai untuk memilih model.
        'model' => env('HERMES_MODEL'),
        'provider' => env('HERMES_PROVIDER', 'custom'),
        'base_url' => env('HERMES_BASE_URL', 'http://127.0.0.1:8317/v1'),
        'max_turns' => (int) env('HERMES_MAX_TURNS', 50),
        // Harus < timeout job (1800) agar Hermes yang menggantung gagal
        // bersih sebelum worker membunuh job.
        'timeout' => (int) env('HERMES_TIMEOUT', 1500),
    ],

    /*
    |--------------------------------------------------------------------------
    | OAuth providers
    |--------------------------------------------------------------------------
    | Key = CLIProxyAPI provider slug used by the management API
    | ({slug}-auth-url). Label/description shown in the AI Providers menu.
    */
    'providers' => [
        'antigravity' => [
            'label' => 'Google (Gemini)',
            'description' => 'Login dengan akun Google — memakai kuota langganan Gemini/Antigravity kamu.',
            'models' => ['gemini-3.8-flash', 'gemini-3.1-pro'],
        ],
        'codex' => [
            'label' => 'ChatGPT (Codex)',
            'description' => 'Login dengan akun ChatGPT — memakai kuota langganan Codex kamu.',
            'models' => ['gpt-6-astra', 'gpt-6.1-sol', 'gpt-6-luna'],
        ],
        'anthropic' => [
            'label' => 'Claude',
            'description' => 'Login dengan akun Claude — memakai kuota langganan Claude Pro/Max kamu.',
            'models' => ['claude-fable-5.1', 'claude-opus-5.5', 'claude-sonnet-5.5'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pemetaan model gateway -> provider (multi-API, multi-akun)
    |--------------------------------------------------------------------------
    | Registry ini yang dipakai modelsByProvider() dan pencatatan
    | pemakaian token untuk mengenali provider dari sebuah model
    | gateway — BUKAN untuk memilih model (aturan tanpa-default:
    | tidak ada pemetaan otomatis ke model per provider). Provider
    | BARU (API key maupun OAuth) cukup ditambahkan di sini — tanpa
    | mengubah logika routing — asalkan gateway mendaftarkan
    | modelnya: pola id/owned_by di bawah yang menentukan grupnya.
    | Akun ganda per provider (multi-akun) TIDAK diatur di sini: itu
    | urusan gateway (rotasi + failover antar kandidat auth untuk
    | model yang sama), bukan aplikasi.
    | Key = slug grup; 'label' = label pemakaian token.
    */
    'model_providers' => [
        'google' => ['label' => 'Google', 'id' => ['gemini'], 'owned_by' => ['google', 'antigravity']],
        'codex' => ['label' => 'OpenAI', 'id' => ['gpt', 'codex'], 'owned_by' => ['openai']],
        'anthropic' => ['label' => 'Anthropic', 'id' => ['claude'], 'owned_by' => ['anthropic']],
        'mimo' => ['label' => 'Mimo', 'id' => ['mimo'], 'owned_by' => ['mimo']],
        'deepseek' => ['label' => 'DeepSeek', 'id' => ['deepseek'], 'owned_by' => ['deepseek']],
        'glm' => ['label' => 'GLM', 'id' => ['glm'], 'owned_by' => ['glm', 'zhipu']],
        'kimi' => ['label' => 'Kimi', 'id' => ['kimi'], 'owned_by' => ['kimi', 'moonshot']],
    ],
];
