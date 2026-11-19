<?php

namespace App\Services;

use App\Support\Projects\ProjectEnvironment;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\Process\Process;

/**
 * Login dan logout akun Google untuk Antigravity CLI dari halaman AI Model.
 *
 * agy hanya menyediakan login lewat TUI interaktif, jadi helper
 * bin/agy-login-helper.py menjalankannya di terminal virtual di latar
 * belakang: memilih Google OAuth, menangkap URL login, lalu menunggu kode
 * otorisasi yang ditempel PO di halaman. Kode tidak pernah disimpan di
 * database atau log; file kode dihapus helper segera setelah dipakai.
 */
class AntigravityLogin
{
    /** Status sesi yang masih berjalan (belum selesai atau gagal). */
    public const ACTIVE = ['starting', 'waiting_code', 'submitting'];

    public function __construct(private readonly AntigravityClient $client) {}

    /** Mulai sesi login baru dan kembalikan ID-nya. */
    public function startLogin(): string
    {
        return $this->spawn('login');
    }

    /** Mulai sesi logout dan kembalikan ID-nya. */
    public function startLogout(): string
    {
        return $this->spawn('logout');
    }

    /**
     * Status sesi: starting | already_logged_in | waiting_code | submitting |
     * submitted | timeout | error | missing.
     *
     * @return array{status: string, url?: string, message?: string, updated_at?: int}
     */
    public function state(string $session): array
    {
        $file = $this->folder($session).'/state.json';
        if (! is_file($file)) {
            return ['status' => is_dir($this->folder($session)) ? 'starting' : 'missing'];
        }

        $state = json_decode((string) file_get_contents($file), true);
        if (! is_array($state) || ! isset($state['status'])) {
            return ['status' => 'starting'];
        }

        // Helper yang mati di tengah jalan tidak boleh membuat halaman menunggu selamanya.
        if (in_array($state['status'], self::ACTIVE, true) && time() - (int) ($state['updated_at'] ?? 0) > 660) {
            return ['status' => 'timeout', 'message' => 'Sesi login berhenti tanpa kabar. Mulai login ulang.'];
        }

        if (in_array($state['status'], ['submitted', 'already_logged_in'], true)) {
            $this->client->forgetModelsCache();
        }

        return $state;
    }

    /** Teruskan kode otorisasi dari halaman ke helper. */
    public function submitCode(string $session, string $code): void
    {
        $code = trim($code);
        if ($code === '' || strlen($code) > 1024 || ! preg_match('#^[A-Za-z0-9\-_./~%+=]+$#', $code)) {
            throw new InvalidArgumentException('Format kode otorisasi tidak dikenali. Salin ulang kode dari halaman Google.');
        }
        if (($this->state($session)['status'] ?? null) !== 'waiting_code') {
            throw new InvalidArgumentException('Sesi login ini tidak sedang menunggu kode. Mulai login ulang.');
        }

        $file = $this->folder($session).'/code';
        File::put($file.'.tmp', $code);
        @chmod($file.'.tmp', 0600);
        File::move($file.'.tmp', $file);
    }

    private function spawn(string $mode): string
    {
        $session = Str::uuid()->toString();
        $folder = $this->folder($session);
        File::ensureDirectoryExists($folder, 0700);
        $this->pruneOldSessions();

        $command = implode(' ', array_map('escapeshellarg', [
            'python3', '-I', base_path('bin/agy-login-helper.py'), $mode, $folder, $this->client->binary(),
        ]));
        // setsid + nohup: helper tetap hidup setelah request selesai. Folder
        // kerjanya sandbox bersama, karena agy berjalan sebagai user lain
        // (sidik) yang tidak bisa membuka folder sesi milik www-data.
        $workDir = (string) config('ai.antigravity.sandbox_dir', storage_path('app/private/agy-sandbox'));
        File::ensureDirectoryExists($workDir, 0775);
        // Lingkungan disaring: helper dan agy tidak mewarisi .env ScrumSpace.
        Process::fromShellCommandline('nohup setsid '.$command.' > '.escapeshellarg($folder.'/helper.log').' 2>&1 < /dev/null &', $workDir, ProjectEnvironment::scrubbed())->run();

        return $session;
    }

    private function folder(string $session): string
    {
        if (! Str::isUuid($session)) {
            throw new InvalidArgumentException('ID sesi login tidak valid.');
        }

        return storage_path('app/private/agy-login/'.$session);
    }

    /** Hapus folder sesi yang lebih tua dari satu hari. */
    private function pruneOldSessions(): void
    {
        foreach (File::directories(storage_path('app/private/agy-login')) as $dir) {
            if (filemtime($dir) < time() - 86400) {
                File::deleteDirectory($dir);
            }
        }
    }
}
