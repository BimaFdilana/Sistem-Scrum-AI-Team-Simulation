<?php

namespace App\Services;

class ChatDisclosurePolicy
{
    public const SAFE_RESPONSE = 'Maaf, saya tidak dapat memberikan direktori, file, konfigurasi, kredensial, atau detail internal server. Saya bisa membantu kebutuhan produk dan workflow Scrum.';

    public const BRAND_RESPONSE = 'Saya adalah AI Agent Scrum Master dan menggunakan tools AI Agent yang tersedia di workspace. Saya fokus membantu kebutuhan produk dan workflow Scrum; detail implementasi internal tidak dibagikan.';

    public function safeResponseFor(string $message, ?string $agentName = null): ?string
    {
        if (preg_match('/\bhermes\b/i', $message) === 1) {
            if ($agentName !== null && $agentName !== 'Scrum Master') {
                return 'Saya adalah AI Agent '.$agentName.' dan menggunakan tools AI Agent yang tersedia di workspace. Detail implementasi internal tidak dibagikan.';
            }

            return self::BRAND_RESPONSE;
        }

        return $this->isRestrictedRequest($message) ? self::SAFE_RESPONSE : null;
    }

    public function isRestrictedRequest(string $message): bool
    {
        return preg_match('/\b(server|directory|directories|direktori|folder|filesystem|file system|file path|path|jalur|konfigurasi|configuration|environment variable|\.env|credential|kredensial|secret|rahasia|api key|token|terminal|command line|shell|root folder|source code|source file|file server)\b/i', $message) === 1;
    }

    public function containsSensitiveDisclosure(string $response): bool
    {
        return preg_match('/(?:\/home\/|\/root\/|\/etc\/|\/opt\/|\/var\/|\/srv\/|\/tmp\/|[A-Z]:\\\\|\b127\.0\.0\.1(?::\d+)?\b|\blocalhost(?::\d+)?\b|\b(?:API[_-]?KEY|SECRET|PASSWORD|TOKEN)\s*[:=]|-----BEGIN [A-Z ]*PRIVATE KEY-----|\bserver\b|\bdirector(?:y|ies)\b|\bfolders?\b|\bfilesystem\b|\bfile path\b|\benvironment variables?\b|\bcredentials?\b|\bAPI keys?\b|\bsecrets?\b|\baccess tokens?\b|\bhermes\b)/i', $response) === 1;
    }
}
