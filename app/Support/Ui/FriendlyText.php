<?php

namespace App\Support\Ui;

/**
 * Teks untuk tampilan: nama alat internal (OpenCode, Hermes, kanban, CLIProxyAPI)
 * tidak ditampilkan ke PO. Dipakai saat MENAMPILKAN pesan, ringkasan, dan galat,
 * sehingga data lama yang tersimpan dengan nama alat juga tampil netral.
 * Log server dan data mentah tetap utuh untuk diagnosis.
 */
class FriendlyText
{
    /** @var array<string, string> */
    private const WORDS = [
        '/agen coding eksternal/i' => 'developer AI',
        '/\bworker\s+kanban\b/i' => 'agen cadangan',
        '/\b(?:board|papan)\s+kanban\b/i' => 'antrean kerja',
        '/\bkartu\s+kanban\b/i' => 'kartu kerja',
        '/\bkanban\b/i' => 'antrean kerja',
        '/\b(?:worker|profile)\s+Hermes\b/i' => 'agen cadangan',
        '/\bHermes\b/i' => 'agen cadangan',
        '/\bOpenCode\b/i' => 'developer AI',
        '/\bCLIProxyAPI\b/i' => 'gateway AI',
        '/\/usr\/bin\/(?:opencode|hermes)/i' => 'developer AI',
    ];

    /** @var array<string, string> */
    private const CODES = [
        'OPENCODE_FAILED' => 'DEVELOPER_AI_GAGAL',
        'OPENCODE_NO_CHANGES' => 'TANPA_PERUBAHAN_KODE',
        'OPENCODE_FALLBACK' => 'DIALIHKAN_KE_AGEN_CADANGAN',
        'KANBAN_BLOCKED' => 'AGEN_CADANGAN_BERHENTI',
        'KANBAN_POLL_FAILED' => 'AGEN_CADANGAN_TIDAK_TERPANTAU',
        'KANBAN_NO_CHANGES' => 'TANPA_PERUBAHAN_KODE',
        'HERMES_PROFILE_NOT_FOUND' => 'PROFIL_AGEN_TIDAK_ADA',
        'HERMES_PROFILE_MISSING' => 'PROFIL_AGEN_TIDAK_ADA',
    ];

    public static function clean(?string $text): string
    {
        $text = (string) $text;
        if ($text === '') {
            return '';
        }

        // Kode warna terminal ("\e[91m", atau sisa "[0m" tanpa karakter escape).
        $text = (string) preg_replace('/\e\[[0-9;]*m|\[[0-9;]{1,6}m/', '', $text);

        // Keluaran proses mentah ("The process "'/usr/bin/opencode' 'run' '...prompt...'" exceeded the timeout of 1500 seconds.")
        // diringkas menjadi satu kalimat; isi prompt tidak perlu dibaca PO.
        $text = (string) preg_replace_callback(
            '/The process\s+"?\'?[^\s"\']*(?:opencode|hermes)\b[\s\S]*?(?:exceeded the timeout of (\d+) seconds\.?|$)/i',
            fn (array $m) => isset($m[1]) && $m[1] !== ''
                ? 'Proses developer AI melewati batas waktu ('.max(1, (int) round(((int) $m[1]) / 60)).' menit).'
                : 'Proses developer AI berhenti dengan galat.',
            $text
        );

        return (string) preg_replace(array_keys(self::WORDS), array_values(self::WORDS), $text);
    }

    public static function code(?string $code): string
    {
        $code = (string) $code;

        return self::CODES[$code] ?? str_ireplace(['OPENCODE', 'KANBAN', 'HERMES'], ['DEVELOPER_AI', 'ANTREAN_KERJA', 'AGEN_CADANGAN'], $code);
    }
}
