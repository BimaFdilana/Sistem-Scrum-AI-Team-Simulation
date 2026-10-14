<?php

namespace App\Support\Workspace;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Menerapkan patch dari agent ke worktree (keputusan Bima: opsi A —
 * agent TIDAK pegang shell; sistem yang menulis file).
 *
 * Format: [['path' => 'relatif/ke/file.php', 'action' => 'create|update|delete', 'content' => '...']]
 */
class PatchApplier
{
    public const MAX_FILES = 50;

    public const MAX_FILE_BYTES = 512000; // 500 KB per file

    /**
     * Pola destruktif yang ditolak mentah-mentah pada perintah perbaikan
     * ($allowDelete = false). Guardrail sistem: larangan "operasi destruktif"
     * tidak boleh hanya hidup di prompt AI — harus di-enforce di sini.
     * key = label untuk pesan error, value = regex (case-insensitive).
     */
    private const DESTRUCTIVE_PATTERNS = [
        'penghapusan tabel (dropIfExists)' => '/dropifexists\s*\(/i',
        'penghapusan tabel (Schema::drop)' => '/schema::drop\s*\(/i',
        'penghapusan kolom/tabel (->drop())' => '/->drop\s*\(/i',
        'pengosongan tabel (truncate)' => '/truncate\s*\(/i',
        // DB::table('x')->delete() TANPA where di antaranya = hapus massal.
        // Varian ber-where (mis. ->where(...)->delete()) tidak cocok pola ini.
        'hapus massal tanpa filter (DB::table()->delete())' => '/DB::table\s*\([^)]*\)\s*->delete\s*\(/i',
        'hapus massal tanpa filter (::query()->delete())' => '/::query\s*\(\s*\)\s*->delete\s*\(/i',
    ];

    /**
     * @param  bool  $allowDelete  false untuk perintah perbaikan: penghapusan file
     *                            ditolak di level sistem (guardrail anti-destruktif),
     *                            dan isi patch dipindai untuk pola destruktif
     *                            (drop tabel, truncate, hapus massal).
     * @return string[] path yang berhasil diterapkan (relatif)
     */
    public function apply(string $worktreePath, array $files, bool $allowDelete = true): array
    {
        $real = realpath($worktreePath);
        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException('Worktree tidak valid.');
        }
        if (count($files) > self::MAX_FILES) {
            throw new RuntimeException('Patch melebihi batas '.self::MAX_FILES.' file.');
        }

        $applied = [];
        foreach ($files as $index => $file) {
            $applied[] = $this->applyOne($real, $file, $index, $allowDelete);
        }

        return $applied;
    }

    private function applyOne(string $realWorktree, mixed $file, int $index, bool $allowDelete): string
    {
        if (! is_array($file)) {
            throw new RuntimeException("Patch #{$index} bukan array.");
        }
        $action = $file['action'] ?? null;
        if (! in_array($action, ['create', 'update', 'delete'], true)) {
            throw new RuntimeException("Patch #{$index}: action tidak valid.");
        }
        if ($action === 'delete' && ! $allowDelete) {
            throw new RuntimeException("Patch #{$index}: penghapusan file tidak diizinkan pada perintah perbaikan.");
        }
        $relative = $this->safeRelativePath($file['path'] ?? null, $index);
        $target = $realWorktree.DIRECTORY_SEPARATOR.$relative;
        $this->assertInsideWorktree($realWorktree, $target, $index);

        if ($action === 'delete') {
            if (File::exists($target) && ! File::isDirectory($target)) {
                File::delete($target);
            }

            return $relative;
        }

        $content = $file['content'] ?? null;
        if (! is_string($content)) {
            throw new RuntimeException("Patch #{$index}: content harus string.");
        }
        if (strlen($content) > self::MAX_FILE_BYTES) {
            throw new RuntimeException("Patch #{$index}: file melebihi 500 KB.");
        }
        if (str_contains($content, "\0")) {
            throw new RuntimeException("Patch #{$index}: content mengandung null byte.");
        }
        if (! $allowDelete) {
            $this->assertNotDestructive($content, $index);
        }

        File::ensureDirectoryExists(dirname($target), 0755);
        File::put($target, $content);

        return $relative;
    }

    private function safeRelativePath(mixed $path, int $index): string
    {
        if (! is_string($path) || trim($path) === '') {
            throw new RuntimeException("Patch #{$index}: path kosong.");
        }
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, "\0")) {
            throw new RuntimeException("Patch #{$index}: path tidak valid.");
        }
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new RuntimeException("Patch #{$index}: path traversal ditolak.");
            }
            $segments[] = $segment;
        }
        if ($segments === []) {
            throw new RuntimeException("Patch #{$index}: path kosong.");
        }
        foreach ($segments as $segment) {
            if (strtolower($segment) === '.git') {
                throw new RuntimeException("Patch #{$index}: path tidak diizinkan.");
            }
        }

        return implode('/', $segments);
    }

    /**
     * Pastikan target akhir tetap di dalam worktree walau ada symlink di
     * tengah path. Resolve leluhur terdalam yang sudah ada (symlink ikut
     * ter-resolve oleh realpath), lalu pastikan berawalan worktree.
     * Dipanggil SEBELUM menulis/menghapus apa pun.
     */
    private function assertInsideWorktree(string $realWorktree, string $target, int $index): void
    {
        $probe = dirname($target);
        while (! file_exists($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) {
                break; // sudah di root filesystem
            }
            $probe = $parent;
        }
        $realParent = realpath($probe);
        $prefix = rtrim($realWorktree, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $resolved = $realParent === false
            ? ''
            : rtrim($realParent, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (! str_starts_with($resolved, $prefix)) {
            throw new RuntimeException("Patch #{$index}: path keluar dari worktree (symlink tidak diizinkan).");
        }
    }

    /**
     * Tolak isi patch yang mengandung pola destruktif pada perintah perbaikan.
     * Hanya aktif bila $allowDelete = false (konteks repair order); eksekusi
     * normal tetap boleh membawa migrasi.
     */
    private function assertNotDestructive(string $content, int $index): void
    {
        foreach (self::DESTRUCTIVE_PATTERNS as $label => $pattern) {
            if (preg_match($pattern, $content) === 1) {
                throw new RuntimeException(
                    "Patch #{$index}: ditolak guardrail anti-destruktif — terdeteksi {$label} pada perintah perbaikan."
                );
            }
        }
    }
}
