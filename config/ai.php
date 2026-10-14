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
        // Ketahanan chat: berapa kali mencoba per model (backoff 1s,2s,4s…)
        // dan berapa model cadangan dari provider yang sama bila model utama
        // tak ditemukan/terus gagal.
        'chat_attempts' => (int) env('CLIPROXY_CHAT_ATTEMPTS', 3),
        'chat_fallbacks' => (int) env('CLIPROXY_CHAT_FALLBACKS', 2),
        // Budget waktu absolut (detik) untuk seluruh loop retry chatResilient.
        // Mencegah fatal error PHP bila upstream hang (harus < set_time_limit controller).
        'chat_deadline' => (int) env('CLIPROXY_CHAT_DEADLINE', 240),
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
];
