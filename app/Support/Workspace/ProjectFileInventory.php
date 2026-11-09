<?php

namespace App\Support\Workspace;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Inventaris file nyata dari baseline project PO.
 *
 * Dipakai prompt breakdown Scrum Master: SEBELUM ini, SM mengarang path
 * (mis. `app/Http/Controllers/OrderController.php` yang tidak ada) dan
 * `resources/views/orders/index.blade.php`, sehingga worker kanban
 * memblokir task karena FILE TERKAIT hanya berisi path yang tidak ada
 * dan kriteria menuntut file yang tidak diizinkan (run #1, 3 Nov 2026).
 *
 * Kelas ini hanya MEMBACA: tidak mengubah project, tidak menyentuh
 * storage, dan mengembalikan null bila workspace belum tersedia.
 */
class ProjectFileInventory
{
    /** Batas karakter bagian prompt agar prompt tidak meledak. */
    public const MAX_PROMPT_CHARS = 7000;

    /** Direktori yang tidak relevan untuk perencanaan task. */
    private const SKIP_DIRS = [
        '.git', 'vendor', 'node_modules', 'storage', 'bootstrap/cache',
        'public/build', 'public/vendor', 'public/hot', 'tests/coverage',
        '.idea', '.vscode',
    ];

    /** Ekstensi yang wajar untuk file baru. */
    private const ALLOWED_NEW_EXTENSIONS = [
        'php', 'blade.php', 'js', 'css', 'json', 'md', 'yml', 'yaml', 'txt', 'xml',
    ];

    /** @var array<string, true>|null */
    private ?array $fileSet = null;

    /** @var array<string, true>|null */
    private ?array $dirSet = null;

    private ?string $promptSection = null;

    private function __construct(private readonly string $root) {}

    public static function forProject(?object $project): ?self
    {
        $path = $project?->workspace_path ?? null;

        if (! is_string($path) || $path === '' || ! is_dir($path)) {
            return null;
        }

        return new self(rtrim($path, '/'));
    }

    public function root(): string
    {
        return $this->root;
    }

    /** Apakah path relatif ini file yang sudah ada di project. */
    public function has(string $relative): bool
    {
        return isset($this->files()[$this->normalize($relative)]);
    }

    /** Apakah direktori relatif ini ada di project. */
    public function hasDir(string $relative): bool
    {
        $dir = trim($this->normalize($relative), '/');

        return $dir === ''
            || $dir === '.'
            || isset($this->dirs()[$dir]);
    }

    /** Apakah ekstensi path ini wajar untuk file baru. */
    public function isPlausibleNewFile(string $relative): bool
    {
        $path = $this->normalize($relative);

        foreach (self::ALLOWED_NEW_EXTENSIONS as $extension) {
            if (str_ends_with($path, '.'.$extension)) {
                return true;
            }
        }

        return false;
    }

    public function normalize(string $relative): string
    {
        $path = str_replace('\\', '/', trim($relative));
        $path = preg_replace('#/+#', '/', $path) ?? $path;

        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        return ltrim($path, '/');
    }

    /** Apakah project punya file blade (dasar cek "task tampilan wajib blade"). */
    /**
     * Daftar file nyata project (dipakai untuk melengkapi scope task view).
     *
     * @return array<int, string>
     */
    public function all(): array
    {
        // files() memakai path sebagai key.
        return array_keys($this->files());
    }

    public function hasViews(): bool
    {
        foreach (array_keys($this->files()) as $file) {
            if (str_starts_with($file, 'resources/views/') && str_ends_with($file, '.blade.php')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bagian prompt berisi path nyata, dibatasi MAX_PROMPT_CHARS.
     */
    public function promptSection(): string
    {
        if ($this->promptSection !== null) {
            return $this->promptSection;
        }

        $groups = [
            'Controller' => $this->collect('app/Http/Controllers', 90),
            'Model' => $this->collect('app/Models', 60),
            'Helper/Support' => array_merge(
                $this->collect('app/Helpers', 30),
                $this->collect('app/Support', 40),
            ),
            'Route' => $this->collect('routes', 10),
            'Middleware' => $this->collect('app/Http/Middleware', 20),
        ];

        $lines = [];
        foreach ($groups as $label => $files) {
            if ($files !== []) {
                $lines[] = $label.': '.implode(', ', $files);
            }
        }

        $views = $this->viewGroups();
        if ($views !== []) {
            $lines[] = 'View (per folder, tulis path lengkapnya):';
            foreach ($views as $dir => $files) {
                $lines[] = '  resources/views/'.$dir.': '.implode(', ', $files);
            }
        }

        $section = "STRUKTUR PROJECT NYATA (path relatif dari baseline project; JANGAN mengarang nama file/direktori di luar daftar ini — untuk file baru, pakai direktori yang ada di daftar dan ikuti penamaannya):\n"
            .($lines === [] ? '(inventaris kosong)' : implode("\n", $lines));

        if (mb_strlen($section) > self::MAX_PROMPT_CHARS) {
            $section = mb_substr($section, 0, self::MAX_PROMPT_CHARS)."\n… (daftar dipotong; tetap pakai pola path di atas)";
        }

        return $this->promptSection = $section;
    }

    /** @return array<int, string> path relatif file di bawah $dir (terbatas $limit). */
    private function collect(string $dir, int $limit): array
    {
        $prefix = trim($dir, '/').'/';
        $result = [];

        foreach (array_keys($this->files()) as $file) {
            if (str_starts_with($file, $prefix)) {
                $result[] = $file;
            }
            if (count($result) >= $limit) {
                break;
            }
        }

        sort($result);

        return $result;
    }

    /** @return array<string, array<int, string>> folder view => nama file (maks 10 per folder). */
    private function viewGroups(): array
    {
        $groups = [];

        foreach (array_keys($this->files()) as $file) {
            if (! str_starts_with($file, 'resources/views/') || ! str_ends_with($file, '.blade.php')) {
                continue;
            }

            $relative = substr($file, strlen('resources/views/'));
            $dir = dirname($relative);
            $name = basename($relative);
            $dir = $dir === '.' ? '' : $dir.'/';

            $groups[$dir] ??= [];
            if (count($groups[$dir]) < 10 && ! in_array($name, $groups[$dir], true)) {
                $groups[$dir][] = $name;
            }
        }

        ksort($groups);

        return $groups;
    }

    /** @return array<string, true> */
    private function files(): array
    {
        if ($this->fileSet !== null) {
            return $this->fileSet;
        }

        $this->scan();

        return $this->fileSet;
    }

    /** @return array<string, true> */
    private function dirs(): array
    {
        if ($this->dirSet !== null) {
            return $this->dirSet;
        }

        $this->scan();

        return $this->dirSet;
    }

    private function scan(): void
    {
        $files = [];
        $dirs = [];

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
                    function (SplFileInfo $current): bool {
                        if (! $current->isDir()) {
                            return true;
                        }

                        $relative = $this->normalize(substr($current->getPathname(), strlen($this->root) + 1));

                        foreach (self::SKIP_DIRS as $skip) {
                            if ($relative === $skip || str_starts_with($relative, $skip.'/')) {
                                return false;
                            }
                        }

                        return true;
                    }
                ),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                /** @var SplFileInfo $file */
                $relative = $this->normalize(substr($file->getPathname(), strlen($this->root) + 1));

                if ($file->isDir()) {
                    $dirs[$relative] = true;

                    continue;
                }

                $files[$relative] = true;
            }
        } catch (\Throwable) {
            // Workspace tidak terbaca: biarkan inventaris kosong; prompt dan
            // validasi akan jatuh ke perilaku lama (tanpa inventaris).
        }

        $this->fileSet = $files;
        $this->dirSet = $dirs;
    }
}
