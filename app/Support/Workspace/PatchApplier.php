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
     * @param  bool  $allowDelete  false untuk perintah perbaikan: penghapusan file
     *                            ditolak di level sistem (guardrail anti-destruktif).
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
        if ($segments === [] || strtolower($segments[0]) === '.git') {
            throw new RuntimeException("Patch #{$index}: path tidak diizinkan.");
        }

        return implode('/', $segments);
    }
}
