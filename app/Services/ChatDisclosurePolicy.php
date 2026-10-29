<?php

namespace App\Services;

/**
 * Aturan pembatasan yang disetujui PO (2026-10-29):
 *
 * - PLATFORM ScrumSpace sendiri = TERTUTUP TOTAL: source code website
 *   ScrumTeam, informasi server, informasi AI yang digunakan
 *   (provider/model/gateway), dan segala tools latar belakang yang
 *   menjalankan website (queue worker, webhook, tunnel, dsb.).
 * - PROJECT PO (dibuat di aplikasi / diimpor dari GitHub-nya) = TERBUKA
 *   TOTAL: saat tim Scrum bekerja atas project itu, tidak ada batasan
 *   terhadap projectnya — termasuk env dan kunci tersembunyi project itu.
 *
 * Konsekuensi implementasi yang penting: filter TIDAK BOLEH bekerja
 * berdasarkan kata biasa ("server", "credentials", "secrets", "file",
 * "direktori") karena kata-kata itu adalah kosakata sah dalam pembahasan
 * project target — dan justru itulah yang menggugurkan vote QA di estimasi
 * US-01 Run #1–#6. Yang disaring hanyalah:
 *  (a) pertanyaan user yang jelas-jelas menanyakan PLATFORM (isRestrictedRequest:
 *      pertanyaan itu harus menunjuk platform — nama/merek/host platform,
 *      "website/situs/aplikasi/platform ini", atau tools/AI platform),
 *  (b) NILAI yang hanya mungkin berasal dari kebocoran platform:
 *      blok private key dan path root platform (matchedSecretCategories).
 * Agen tidak pernah menerima secret platform di konteksnya, sehingga
 * kemunculan dua pola itu di jawaban adalah sinyal kebocoran, bukan
 * pembahasan project yang sah. Pola KEY=, localhost, dan kata-kata
 * keamanan TIDAK disaring: itu bisa jadi detail project PO yang sah
 * dan memang diizinkan oleh aturan di atas.
 */
class ChatDisclosurePolicy
{
    public const SAFE_RESPONSE = 'Maaf, saya tidak dapat memberikan direktori, file, konfigurasi, kredensial, atau detail internal server. Saya bisa membantu kebutuhan produk dan workflow Scrum.';

    public const BRAND_RESPONSE = 'Saya adalah AI Agent Scrum Master dan menggunakan tools AI Agent yang tersedia di workspace. Saya fokus membantu kebutuhan produk dan workflow Scrum; detail implementasi internal tidak dibagikan.';

    public function safeResponseFor(string $message, ?string $agentName = null, bool $projectContext = false): ?string
    {
        if (preg_match('/\bhermes\b/i', $message) === 1) {
            if ($agentName !== null && $agentName !== 'Scrum Master') {
                return 'Saya adalah AI Agent '.$agentName.' dan menggunakan tools AI Agent yang tersedia di workspace. Detail implementasi internal tidak dibagikan.';
            }

            return self::BRAND_RESPONSE;
        }

        return $this->isRestrictedRequest($message, $projectContext) ? self::SAFE_RESPONSE : null;
    }

    /**
     * Benar hanya bila pertanyaan user menunjuk PLATFORM ScrumSpace
     * sendiri — ATAU bila chat tidak punya konteks project sama sekali
     * ("Tanpa project"): tanpa project, pertanyaan soal direktori/server
     * hanya bisa bermakna platform, jadi gerbang lama yang luas tetap
     * berlaku di sana. Pertanyaan tentang project PO (mis. ".env project
     * ini", "server-side rendering project X") sengaja TIDAK dibatasi.
     */
    public function isRestrictedRequest(string $message, bool $projectContext = false): bool
    {
        // Kata tunggal dibatasi huruf di kedua sisi agar "ai" tidak cocok
        // di dalam "lain"/"baik" (dan ".env" tetap cocok sesudah spasi).
        $words = '(server|directory|directories|direktori|folder|filesystem|path|jalur|konfigurasi|configuration|\.env|credential|kredensial|secret|rahasia|token|terminal|shell|ai|tools?)';
        $phrases = '(file system|file path|environment variable|command line|root folder|source code|source file|file server|api key|latar belakang)';

        if (! $projectContext
            && (preg_match('/(?<![A-Za-z])'.$words.'(?![A-Za-z])/i', $message) === 1
                || preg_match('/'.$phrases.'/i', $message) === 1)) {
            return true;
        }

        // Penanda platform + kata/frasa pengungkapan di pesan yang sama.
        $platform = '(scrumspace|scrum-team|scrum team|artitechlabs|website ini|situs ini|aplikasi ini|platform ini)';
        if (preg_match('/'.$platform.'/i', $message) === 1
            && (preg_match('/(?<![A-Za-z])'.$words.'(?![A-Za-z])/i', $message) === 1
                || preg_match('/'.$phrases.'/i', $message) === 1)) {
            return true;
        }

        // Pertanyaan tentang AI / tools latar belakang platform itu sendiri.
        if (preg_match('/\b(cliproxy|cli proxy|queue worker|webhook|tunnel|gateway ai|provider ai|model ai)\b/i', $message) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Kategori pola kebocoran platform yang cocok di teks. Hanya dua
     * kategori: blok private key dan path root platform. Kosakata biasa
     * ("server", "credentials", "secrets", "API keys") dan pola KEY=
     * sengaja tidak termasuk: itu bisa jadi detail project PO yang sah.
     */
    public function matchedSecretCategories(string $text): array
    {
        $categories = [];

        if (preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----/', $text) === 1) {
            $categories[] = 'private_key';
        }

        // Path root platform — tapi HANYA pohon source platform
        // (app/, config/, routes/, ...). Path workspace project
        // (storage/app/private/project-workspaces/...) sengaja TIDAK
        // termasuk: itu detail project PO yang sah dan diizinkan.
        $base = function_exists('app') ? (string) app()->basePath() : '';
        $platformDirs = '(app|bootstrap|config|database|public|resources|routes)';
        if ($base !== '' && (preg_match('#'.preg_quote($base, '#').'/'.$platformDirs.'/#', $text) === 1
            || str_contains($text, $base.'/.env')
            || str_contains($text, $base.'/artisan')
            || str_contains($text, $base.'/composer.json'))) {
            $categories[] = 'platform_path';
        }
        if (preg_match('#/opt/scrumspace/'.$platformDirs.'/#', $text) === 1) {
            $categories[] = 'platform_path';
        }

        return array_values(array_unique($categories));
    }

    /**
     * @deprecated Gunakan matchedSecretCategories() bila kategorinya dibutuhkan.
     * Dipertahankan sebagai alias karena dipakai validasi vote, ringkasan
     * eksekusi, dan filter tampilan chat — semuanya kini memakai definisi
     * sempit yang sama (hanya nilai kebocoran platform).
     */
    public function containsStructuredEvidenceSecret(string $response): bool
    {
        return $this->matchedSecretCategories($response) !== [];
    }

    /**
     * @deprecated Alias dari containsStructuredEvidenceSecret(). Dulu
     * memeriksa kata-kata biasa juga; definisi itu adalah bug yang
     * menggugurkan vote QA, dan kini sudah dipersempit ke nilai
     * kebocoran platform saja.
     */
    public function containsSensitiveDisclosure(string $response): bool
    {
        return $this->matchedSecretCategories($response) !== [];
    }
}
