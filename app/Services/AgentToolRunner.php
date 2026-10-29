<?php

namespace App\Services;

use App\Exceptions\AiUnavailableException;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Loop tool-call untuk pengerjaan task eksekusi — pengganti jalur
 * patch JSON di chat (sumber gagal parse eksekusi US-01 #1–#8).
 *
 * Agent TIDAK pernah memegang shell dan TIDAK pernah mengirim dokumen
 * patch: ia hanya memanggil tool, host yang mengeksekusi tiap tool
 * terhadap worktree terisolasi, memvalidasi path/isi, menjalankan
 * `php -l` untuk file PHP, dan mencatat kondisi semula tiap target
 * sehingga kegagalan di tengah loop mengembalikan worktree utuh.
 * Patch yang dicatat job pemanggil selalu `git diff` host, bukan
 * teks kiriman agent.
 */
class AgentToolRunner
{
    public const MAX_ROUNDS = 12;

    public const MAX_FILES = 50;

    public const MAX_FILE_BYTES = 512000;

    private const DESTRUCTIVE_PATTERNS = [
        'penghapusan tabel (dropIfExists)' => '/dropifexists\s*\(/i',
        'penghapusan tabel (Schema::drop)' => '/schema::drop\s*\(/i',
        'penghapusan kolom/tabel (->drop())' => '/->drop\s*\(/i',
        'pengosongan tabel (truncate)' => '/truncate\s*\(/i',
        'hapus massal tanpa filter (DB::table()->delete())' => '/DB::table\s*\([^)]*\)\s*->delete\s*\(/i',
        'hapus massal tanpa filter (::query()->delete())' => '/::query\s*\(\s*\)\s*->delete\s*\(/i',
    ];

    public function __construct(
        protected AgentProfileClient $agents,
        protected CliProxyApiClient $cliproxy,
    ) {}

    public static function tools(): array
    {
        $string = ['type' => 'string'];

        return [
            ['type' => 'function', 'function' => [
                'name' => 'read_file',
                'description' => 'Baca isi file di worktree (path relatif).',
                'parameters' => ['type' => 'object', 'properties' => ['path' => $string], 'required' => ['path']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'apply_edit',
                'description' => 'Ganti old_text (harus cocok persis tepat satu kali) dengan new_text. old_text kosong berarti tambahkan di akhir file. File harus sudah ada.',
                'parameters' => ['type' => 'object', 'properties' => ['path' => $string, 'old_text' => $string, 'new_text' => $string], 'required' => ['path', 'old_text', 'new_text']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'create_file',
                'description' => 'Buat file baru lengkap dengan isinya. Gagal bila file sudah ada (pakai apply_edit untuk file yang ada).',
                'parameters' => ['type' => 'object', 'properties' => ['path' => $string, 'content' => $string], 'required' => ['path', 'content']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'delete_file',
                'description' => 'Hapus satu file. Ditolak pada perintah perbaikan.',
                'parameters' => ['type' => 'object', 'properties' => ['path' => $string], 'required' => ['path']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'submit_task',
                'description' => 'Selesaikan task dengan ringkasan perubahan. Panggil hanya bila semua perubahan sudah diterapkan lewat tool.',
                'parameters' => ['type' => 'object', 'properties' => ['summary' => $string], 'required' => ['summary']],
            ]],
        ];
    }

    /**
     * @return array{files: string[], summary: string}
     */
    public function run(string $slug, string $worktree, string $taskPrompt, bool $allowDelete): array
    {
        $model = $this->agents->toolModel($slug);
        if ($model === null) {
            throw new RuntimeException('Tool calling tidak tersedia untuk peran '.$slug.' (tidak ada model CLIProxyAPI).');
        }
        $real = realpath($worktree);
        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException('Worktree tidak valid.');
        }
        $system = $this->agents->toolSystemPrompt($slug, true);
        $messages = [['role' => 'user', 'content' => $taskPrompt]];
        $backups = [];
        $createdDirs = [];
        $touched = [];

        try {
            for ($round = 1; $round <= self::MAX_ROUNDS; $round++) {
                $result = $this->cliproxy->chatWithToolsHistory($model, $system, $messages, self::tools());
                if ($result['error'] !== null) {
                    if (AiGateway::isTransient($result['error'])) {
                        throw new AiUnavailableException($result['error'], '', $result['retry_after'] ?? null);
                    }
                    throw new RuntimeException('Tool call gagal: '.$result['error']);
                }
                $calls = $result['tool_calls'];
                if ($calls === []) {
                    $messages[] = ['role' => 'user', 'content' => 'Kamu belum memanggil tool apa pun. Kerjakan task HANYA lewat tool (read_file/apply_edit/create_file/delete_file), lalu akhiri dengan submit_task. Jangan menulis code di teks chat.'];
                    continue;
                }
                $messages[] = ['role' => 'assistant', 'content' => $result['text'], 'tool_calls' => $this->rawToolCalls($calls)];
                foreach ($calls as $call) {
                    $name = $call['name'] ?? null;
                    $args = $call['arguments'] ?? [];
                    if ($name === 'submit_task') {
                        $summary = is_string($args['summary'] ?? null) ? trim($args['summary']) : '';
                        if ($summary === '') {
                            $messages[] = $this->toolMessage($call, 'error: summary tidak boleh kosong.');

                            continue 2;
                        }

                        return ['files' => array_values(array_keys($touched)), 'summary' => $summary];
                    }
                    $outcome = $this->executeTool($real, $name, $args, $allowDelete, $backups, $createdDirs, $touched);
                    $messages[] = $this->toolMessage($call, $outcome);
                }
            }
        } catch (Throwable $e) {
            $this->rollback($backups, $createdDirs);
            throw $e;
        }

        $this->rollback($backups, $createdDirs);
        throw new RuntimeException('Agent tidak menyelesaikan task lewat tool dalam '.self::MAX_ROUNDS.' putaran.');
    }

    /**
     * Bentuk tool_calls mentah untuk riwayat percakapan berikutnya
     * (argumen harus string JSON, seperti format OpenAI).
     */
    private function rawToolCalls(array $calls): array
    {
        $raw = [];
        foreach ($calls as $call) {
            $raw[] = [
                'id' => $call['id'] ?? null,
                'type' => 'function',
                'function' => [
                    'name' => $call['name'] ?? null,
                    'arguments' => json_encode($call['arguments'] ?? []),
                ],
            ];
        }

        return $raw;
    }

    private function toolMessage(array $call, string $content): array
    {
        return ['role' => 'tool', 'tool_call_id' => $call['id'] ?? 'call_unknown', 'content' => $content];
    }

    private function executeTool(string $real, ?string $name, array $args, bool $allowDelete, array &$backups, array &$createdDirs, array &$touched): string
    {
        try {
            return match ($name) {
                'read_file' => $this->toolRead($real, $args),
                'apply_edit' => $this->toolEdit($real, $args, $allowDelete, $backups, $createdDirs, $touched),
                'create_file' => $this->toolCreate($real, $args, $allowDelete, $backups, $createdDirs, $touched),
                'delete_file' => $this->toolDelete($real, $args, $allowDelete, $backups, $touched),
                default => 'error: tool tidak dikenal: '.($name ?? '(kosong)'),
            };
        } catch (RuntimeException $e) {
            return 'error: '.$e->getMessage();
        }
    }

    private function toolRead(string $real, array $args): string
    {
        $target = $this->resolveTarget($real, $args['path'] ?? null);
        if (! is_file($target)) {
            return 'error: file tidak ditemukan: '.($args['path'] ?? '');
        }
        $content = file_get_contents($target);
        if ($content === false || strlen($content) > self::MAX_FILE_BYTES) {
            return 'error: file tidak bisa dibaca atau melebihi 500 KB.';
        }

        return $content;
    }

    private function toolEdit(string $real, array $args, bool $allowDelete, array &$backups, array &$createdDirs, array &$touched): string
    {
        $target = $this->resolveTarget($real, $args['path'] ?? null);
        $old = $args['old_text'] ?? null;
        $new = $args['new_text'] ?? null;
        if (! is_string($old) || ! is_string($new)) {
            return 'error: old_text dan new_text harus string.';
        }
        if (! is_file($target)) {
            return 'error: file belum ada, pakai create_file: '.($args['path'] ?? '');
        }
        $this->validateContent($new, $allowDelete);
        $current = file_get_contents($target);
        if ($current === false) {
            return 'error: file tidak bisa dibaca.';
        }
        if ($old === '') {
            $updated = $current.$new;
        } else {
            if (substr_count($current, $old) !== 1) {
                return 'error: old_text harus cocok persis tepat satu kali (ditemukan '.substr_count($current, $old).' kali). Baca ulang file dengan read_file dan kirim old_text yang persis.';
            }
            $updated = str_replace($old, $new, $current);
        }
        $this->backup($target, $backups);
        if (File::put($target, $updated) === false) {
            return 'error: gagal menulis file.';
        }
        $lint = $this->lintPhp($target);
        if ($lint !== null) {
            $this->restoreOne($target, $backups);

            return 'error: php -l gagal: '.$lint.' Perubahan dibatalkan, perbaiki code lalu coba lagi.';
        }
        $touched[$this->relative($real, $target)] = true;

        return 'ok: '.($args['path'] ?? '');
    }

    private function toolCreate(string $real, array $args, bool $allowDelete, array &$backups, array &$createdDirs, array &$touched): string
    {
        $target = $this->resolveTarget($real, $args['path'] ?? null);
        $content = $args['content'] ?? null;
        if (! is_string($content)) {
            return 'error: content harus string.';
        }
        if (count($touched) >= self::MAX_FILES) {
            return 'error: melebihi batas '.self::MAX_FILES.' file per task.';
        }
        if (is_file($target) || is_dir($target)) {
            return 'error: file sudah ada, pakai apply_edit: '.($args['path'] ?? '');
        }
        $this->validateContent($content, $allowDelete);
        $dir = dirname($target);
        if (! is_dir($dir)) {
            $createdDirs[] = $dir;
            File::ensureDirectoryExists($dir, 0755);
        }
        $this->backup($target, $backups);
        if (File::put($target, $content) === false) {
            return 'error: gagal menulis file.';
        }
        $lint = $this->lintPhp($target);
        if ($lint !== null) {
            $this->restoreOne($target, $backups);

            return 'error: php -l gagal: '.$lint.' Perubahan dibatalkan, perbaiki code lalu coba lagi.';
        }
        $touched[$this->relative($real, $target)] = true;

        return 'ok: '.($args['path'] ?? '');
    }

    private function toolDelete(string $real, array $args, bool $allowDelete, array &$backups, array &$touched): string
    {
        if (! $allowDelete) {
            return 'error: penghapusan file tidak diizinkan pada perintah perbaikan.';
        }
        $target = $this->resolveTarget($real, $args['path'] ?? null);
        if (! is_file($target)) {
            return 'error: file tidak ditemukan: '.($args['path'] ?? '');
        }
        $this->backup($target, $backups);
        if (! @unlink($target)) {
            return 'error: gagal menghapus file.';
        }
        $touched[$this->relative($real, $target)] = true;

        return 'ok: dihapus '.($args['path'] ?? '');
    }

    private function validateContent(string $content, bool $allowDelete): void
    {
        if (strlen($content) > self::MAX_FILE_BYTES) {
            throw new RuntimeException('content melebihi 500 KB.');
        }
        if (str_contains($content, "\0")) {
            throw new RuntimeException('content mengandung null byte.');
        }
        if (! $allowDelete) {
            foreach (self::DESTRUCTIVE_PATTERNS as $label => $pattern) {
                if (preg_match($pattern, $content)) {
                    throw new RuntimeException('konten ditolak (operasi destruktif): '.$label);
                }
            }
        }
    }

    private function lintPhp(string $target): ?string
    {
        if (! str_ends_with($target, '.php')) {
            return null;
        }
        $process = new Process([PHP_BINARY, '-l', $target]);
        $process->run();
        if ($process->isSuccessful()) {
            return null;
        }

        return trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'php -l gagal';
    }

    private function resolveTarget(string $real, mixed $path): string
    {
        if (! is_string($path) || trim($path) === '') {
            throw new RuntimeException('path kosong.');
        }
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, "\0")) {
            throw new RuntimeException('path tidak valid.');
        }
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new RuntimeException('path traversal ditolak.');
            }
            $segments[] = $segment;
        }
        if ($segments === []) {
            throw new RuntimeException('path kosong.');
        }
        if (in_array('.git', $segments, true) || $segments[0] === 'vendor' || $segments[0] === '.env' || str_starts_with($segments[0], '.env.')) {
            throw new RuntimeException('path terlarang: '.$path);
        }

        return $real.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $segments);
    }

    private function relative(string $real, string $target): string
    {
        return ltrim(str_replace($real, '', $target), DIRECTORY_SEPARATOR);
    }

    private function backup(string $target, array &$backups): void
    {
        if (! array_key_exists($target, $backups)) {
            $backups[$target] = is_file($target) ? file_get_contents($target) : null;
        }
    }

    private function restoreOne(string $target, array $backups): void
    {
        if (! array_key_exists($target, $backups)) {
            return;
        }
        if ($backups[$target] === null) {
            if (is_file($target)) {
                @unlink($target);
            }
        } else {
            @file_put_contents($target, $backups[$target]);
        }
    }

    private function rollback(array $backups, array $createdDirs): void
    {
        foreach (array_reverse($backups, true) as $target => $content) {
            $this->restoreOne($target, [$target => $content]);
        }
        usort($createdDirs, static fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($createdDirs as $dir) {
            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }
    }
}
