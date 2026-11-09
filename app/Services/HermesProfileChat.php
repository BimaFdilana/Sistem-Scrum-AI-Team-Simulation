<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Chat satu query ke satu profile Hermes native, lewat CLI.
 *
 * Dipakai jalur estimasi Hermes (ESTIMATION_DRIVER=hermes): vote tiap
 * spesialis dan ringkasan SM dihasilkan profile Hermes itu sendiri
 * (backend-developer / frontend-developer / qa-engineer / scrumagent),
 * dengan MODEL DARI MENU Konfigurasi Agent (Cara B, perintah Pilih
 * 2 Nov 2026, setelah probe VPS). Ronde voting, parsing JSON, deadlock
 * max, dan laporan SM di StoryEstimationService TIDAK berubah — hanya
 * mesin yang menjawab prompt yang diganti.
 *
 * Bukti probe VPS 2 Nov 2026 (Web Terminal sidik, read-only):
 * - `hermes chat --help`: flag `-m, --model MODEL` ada, keterangan
 *   "Model to use (overrides profile)".
 * - `--model` DIHORMATI, bukan diabaikan: model tidak ada gagal
 *   HTTP 400 "unknown provider", model mimo-v2.6-flash menggantikan
 *   glm-5.3 profile di banner dan menjawab.
 * - Nama profile terkonfirmasi: backend-developer, frontend-developer,
 *   qa-engineer, scrumagent (semua glm-5.3 di profile).
 * - Keluar sendiri HANYA bila stdin tertutup: dari terminal (stdin
 *   terbuka) chat menjawab lalu diam di prompt interaktif sampai
 *   timeout; dengan `< /dev/null` ia keluar sendiri dalam 26 detik,
 *   EXIT 0. Symfony Process di bawah memakai input null (= stdin
 *   tertutup, pola yang sama dengan HermesTaskRunner yang terbukti
 *   keluar di live) — karena itu perintah ini HARUS tetap lewat
 *   Process dengan input null, jangan pernah lewat terminal interaktif.
 *
 * Aturan model (sama ketatnya dengan HermesTaskRunner):
 * - Model HANYA dari StrictModelResolver: snapshot AgentConfiguration
 *   terverifikasi DAN masih ada di daftar model gateway. Bila peran
 *   belum memilih / belum terverifikasi / tidak tersedia, Hermes
 *   TIDAK dipanggil sama sekali dan ModelNotChosenException dilempar
 *   (dipetakan ke MODEL_NOT_CHOSEN di StoryEstimationService).
 * - TIDAK ADA fallback diam-diam: bukan ke model profile (glm-5.3),
 *   bukan ke gateway Laravel. Bila profile tidak ada atau CLI gagal,
 *   exception dilempar dan vote gagal dengan kode yang jelas
 *   (HERMES_PROFILE_MISSING / PROVIDER_*), bukan jawaban mesin lain.
 * - Perintah dikirim lewat file prompt (bukan argumen shell) agar teks
 *   apa pun tiba verbatim, dan file dibuat 0644 karena alasan yang
 *   sama dengan HermesTaskRunner: worker www-data membuat file, Hermes
 *   berjalan sebagai sidik dan harus bisa membacanya.
 * - Cwd chat adalah direktori sementara kosong, BUKAN workspace project.
 *   Estimasi adalah diskusi, bukan pengerjaan code — agent tidak boleh
 *   menyentuh file project saat voting, dan prompt juga melarangnya.
 *   Kalau agent tetap mencoba mengubah file, yang berubah hanya file
 *   di direktori sementara itu, bukan project.
 * - Tidak ada --provider/--base-url/--api-key di perintah ini: profile
 *   sudah membawa provider custom-nya sendiri (terbukti di probe),
 *   dan kunci gateway tidak boleh bocor ke argumen chat estimasi.
 */
class HermesProfileChat
{
    private const COMMAND_TIMEOUT = 600;

    /** Nama profile Hermes untuk satu slug role Laravel. */
    public static function profileNameForSlug(string $slug): string
    {
        $found = collect(config('agents.profiles', []))->firstWhere('slug', $slug);
        $profile = is_array($found) ? ($found['profile'] ?? null) : null;

        return is_string($profile) && $profile !== '' ? $profile : $slug;
    }

    /**
     * Satu jawaban chat dari satu profile. Return teks jawaban mentah
     * (biasanya JSON vote, diparse pemanggil seperti jalur gateway).
     */
    public function chat(string $slug, string $prompt, string $context = ''): string
    {
        $profile = self::profileNameForSlug($slug);
        // Ketat, sebelum Hermes dipanggil: model menu saja, tanpa default.
        $model = app(StrictModelResolver::class)->require($slug);
        $binary = config('ai.hermes.binary', 'hermes');
        $workdir = sys_get_temp_dir().'/hermes-chat-'.uniqid();
        if (! mkdir($workdir, 0700, true) && ! is_dir($workdir)) {
            throw new RuntimeException('Gagal membuat direktori kerja chat Hermes.');
        }
        $promptFile = tempnam(sys_get_temp_dir(), 'hermes-chat-');
        if ($promptFile === false) {
            throw new RuntimeException('Gagal membuat file prompt sementara.');
        }
        try {
            file_put_contents($promptFile, $prompt."\n\nATURAN TAMBAHAN: ini hanya diskusi penilaian, jangan mengubah file apa pun dan jangan menjalankan perintah shell/server.");
            chmod($promptFile, 0644);
            // --format stream-json WAJIB, bukan pilihan gaya.
            //
            // Bukti (3 Nov 2026): output `--format text` adalah SELURUH
            // transkrip sesi (diukur 2.472 byte) — prompt di-echo di awal,
            // banner "☤ Hermes", jawaban agent, lalu ekor "Resume this
            // session with:" + "Title:" + statistik sesi. extractJsonObject()
            // lalu mengambil dari '{' pertama (yang ada di ECHO PROMPT kita
            // sendiri) sampai '}' terakhir (yang ada di ekor), sehingga yang
            // tervalidasi bukan jawaban agent melainkan rentang gabungan —
            // inilah sumber INVALID_JSON_SCHEMA yang berulang (32 warning
            // di log produksi, 6 dari 8 estimation run gagal).
            //
            // stream-json memberi satu baris per event; yang kita pakai
            // hanya {"type":"result","text":"<jawaban bersih>"}, terverifikasi
            // berisi JSON vote utuh tanpa banner/echo/ekor.
            $command = [$binary, '-p', $profile, 'chat', '--query-file', $promptFile, '--model', $model, '--format', 'stream-json'];
            $started = microtime(true);
            // Input null = stdin tertutup. Ini BUKAN detail kosmetik:
            // probe VPS membuktikan chat dengan stdin terbuka menggantung
            // di prompt interaktif, sedangkan dengan stdin tertutup
            // (< /dev/null) ia keluar sendiri dalam 26 detik, EXIT 0.
            $process = new Process($command, $workdir, null, null, self::COMMAND_TIMEOUT);
            $process->run();
            $duration = (int) round(microtime(true) - $started);
            $output = trim($process->getOutput());
            $error = trim($process->getErrorOutput());
            if (! $process->isSuccessful()) {
                Log::warning('Hermes profile chat failed', [
                    'profile_slug' => $slug,
                    'profile' => $profile,
                    'model' => $model,
                    'context' => $context,
                    'exit_code' => $process->getExitCode(),
                    'output_excerpt' => mb_substr($output, 0, 2000),
                    'error_excerpt' => mb_substr($error, 0, 2000),
                    'duration_seconds' => $duration,
                ]);
                if (preg_match('/profile.*(not found|unknown|no such)/i', $output.' '.$error) === 1) {
                    throw new RuntimeException('HERMES_PROFILE_NOT_FOUND: profile Hermes '.$profile.' tidak ditemukan.');
                }
                throw new RuntimeException('Hermes profile '.$profile.' gagal menjawab (exit '.$process->getExitCode().').');
            }
            if ($output === '') {
                throw new RuntimeException('Hermes profile '.$profile.' tidak mengembalikan jawaban.');
            }

            return $this->extractResultText($output, $profile);
        } finally {
            @unlink($promptFile);
            @rmdir($workdir);
        }
    }

    /**
     * Ambil teks jawaban dari keluaran `--format stream-json`.
     *
     * Formatnya satu event JSON per baris. Baris terakhir yang relevan
     * berjenis "result" dan membawa jawaban lengkap di field "text":
     *   {"type":"result","session_id":"...","exit_code":0,"text":"<jawaban>"}
     *
     * Fallback berlapis, dari yang paling andal:
     *  1. baris "result" terakhir dengan text tidak kosong;
     *  2. bila tidak ada, gabungkan semua potongan {"type":"text"} —
     *     dipakai saat stream terputus sebelum event result tiba;
     *  3. bila stream-json tidak terbaca sama sekali (mis. versi Hermes
     *     lawas yang mengabaikan flag), kembalikan keluaran apa adanya.
     *     Jalur ini membuat perubahan tetap kompatibel ke belakang alih-alih
     *     gagal total di lingkungan yang belum mendukung flag itu.
     */
    private function extractResultText(string $output, string $profile): string
    {
        $resultText = null;
        $streamed = '';
        $sawJson = false;

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || ! str_starts_with($line, '{')) {
                continue;
            }
            $event = json_decode($line, true);
            if (! is_array($event) || ! isset($event['type'])) {
                continue;
            }
            $sawJson = true;
            if ($event['type'] === 'result' && is_string($event['text'] ?? null) && trim($event['text']) !== '') {
                $resultText = $event['text'];
            } elseif ($event['type'] === 'text' && is_string($event['text'] ?? null)) {
                $streamed .= $event['text'];
            }
        }

        if ($resultText !== null) {
            return trim($resultText);
        }
        if (trim($streamed) !== '') {
            return trim($streamed);
        }
        if ($sawJson) {
            throw new RuntimeException('Hermes profile '.$profile.' mengembalikan stream tanpa teks jawaban.');
        }

        // Bukan stream-json (flag diabaikan versi lama): pakai apa adanya.
        return trim($output);
    }
}
