<?php

namespace App\Support\Chat;

/**
 * Mengenali perintah "hentikan eksekusi" di chat tanpa AI (harus tetap jalan saat
 * kuota AI habis, justru saat itu PO paling butuh menghentikan pekerjaan).
 *
 * Sengaja ketat: kalimat pendek yang DIAWALI kata henti. "Tambahkan tombol batalkan
 * pesanan" bukan perintah henti.
 */
class StopIntent
{
    private const VERBS = ['hentikan', 'berhentikan', 'berhenti', 'stop', 'setop', 'batalkan', 'batal', 'matikan', 'cancel', 'abort', 'setopkan'];

    private const OBJECTS = ['eksekusi', 'proses', 'tim', 'pekerjaan', 'kerjaan', 'run', 'perintah', 'task', 'tugas', 'agen', 'agent', 'developer', 'semua', 'sekarang', 'paksa', 'dulu', 'saja', 'aja', 'ini', 'itu', 'berjalan', 'juga', 'semuanya', 'yang', 'lagi'];

    private const FILLER = ['tolong', 'segera', 'mohon', 'coba', 'ayo', 'oke', 'ok', 'sm', 'scrum', 'master', 'pak', 'bu', 'kak', 'dong', 'ya', 'langsung', 'sekarang'];

    public static function matches(string $text): bool
    {
        $clean = mb_strtolower(trim(preg_replace('/[^\p{L}\p{N}#\s]+/u', ' ', $text) ?? ''));
        $words = preg_split('/\s+/u', $clean, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === [] || count($words) > 12) {
            return false;
        }

        // Lewati kata sapaan di depan ("tolong", "scrum master", ...).
        while ($words !== [] && in_array($words[0], self::FILLER, true)) {
            array_shift($words);
        }
        if ($words === [] || ! in_array($words[0], self::VERBS, true)) {
            return false;
        }
        $rest = array_slice($words, 1);

        // Kata henti saja ("stop", "hentikan") atau diikuti objek yang dikenal / nomor perintah.
        foreach ($rest as $word) {
            if (! in_array($word, self::OBJECTS, true) && ! in_array($word, self::FILLER, true)
                && ! preg_match('/^(#?\d+|perintah\d*|us\d*)$/u', $word)) {
                return false;
            }
        }

        return true;
    }
}
