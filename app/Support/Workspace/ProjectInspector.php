<?php

namespace App\Support\Workspace;

use App\Models\ExecutionMessage;
use App\Models\ExecutionRun;
use Illuminate\Support\Facades\Cache;

/**
 * Read-only inspector untuk skill diskusi AI team (disetujui Bima 2026-10-13).
 *
 * Memberi tiap peran informasi ASIMETRIS tentang project target (milik user)
 * sehingga perbedaan estimasi lahir dari data, bukan akting. Hanya membaca
 * nama struktur (bukan isi file), hasil di-cap dan di-cache.
 */
class ProjectInspector
{
    private const CACHE_TTL = 600;

    private const MAX_ITEMS = 30;

    private const CONTEXT_LIMIT = 1200;

    public function __construct(
        private readonly ?string $workspacePath,
        private readonly string $cacheKey,
    ) {}

    public static function forWorkspace(?string $workspacePath, int|string $projectId): self
    {
        return new self($workspacePath, 'project-'.$projectId);
    }

    /** Dispatch skill name -> konteks teks ('' bila tidak ada data). */
    public function skillContext(string $skill, ?int $projectId = null): string
    {
        return match ($skill) {
            'component_inventory' => $this->componentInventory(),
            'route_list' => $this->routeList(),
            'schema_summary' => $this->schemaSummary(),
            'api_inventory' => $this->apiInventory(),
            'risk_patterns' => $projectId === null ? '' : $this->riskPatterns($projectId),
            default => '',
        };
    }

    public function componentInventory(): string
    {
        return $this->remember('components', function (): string {
            if (! $this->readable()) {
                return '';
            }
            $blades = $this->basenames($this->glob('resources/views/components/**/*.blade.php'), '.blade.php');
            $js = $this->relativePaths($this->glob('resources/js/**/*.{js,ts,vue,jsx,tsx}'));
            $parts = [];
            if ($blades !== []) {
                $parts[] = count($blades).' komponen Blade: '.implode(', ', array_slice($blades, 0, self::MAX_ITEMS));
            }
            if ($js !== []) {
                $parts[] = count($js).' file JS: '.implode(', ', array_slice($js, 0, self::MAX_ITEMS));
            }

            return $parts === [] ? '' : 'Inventaris komponen UI — '.implode('; ', $parts).'.';
        });
    }

    public function routeList(): string
    {
        return $this->remember('routes', function (): string {
            if (! $this->readable()) {
                return '';
            }
            $routes = [];
            foreach ($this->glob('routes/*.php') as $file) {
                $content = $this->readFile($file, 200000);
                if ($content === null) {
                    continue;
                }
                preg_match_all("/Route::(get|post|put|patch|delete|resource|apiResource)\\s*\\(\\s*['\"]([^'\"]+)['\"]/", $content, $m);
                foreach ($m[1] as $i => $verb) {
                    $routes[] = strtoupper($verb).' '.$m[2][$i];
                }
            }
            $routes = array_values(array_unique($routes));
            if ($routes === []) {
                return '';
            }

            return 'Daftar route ('.count($routes).'): '.implode(', ', array_slice($routes, 0, self::MAX_ITEMS)).'.';
        });
    }

    public function schemaSummary(): string
    {
        return $this->remember('schema', function (): string {
            if (! $this->readable()) {
                return '';
            }
            $tables = [];
            foreach ($this->glob('database/migrations/*.php') as $file) {
                $content = $this->readFile($file, 200000);
                if ($content === null) {
                    continue;
                }
                if (! preg_match("/Schema::create\\(\\s*['\"](\\w+)['\"]/", $content, $m)) {
                    continue;
                }
                $table = $m[1];
                preg_match_all('/\\$table->(\\w+)\\(\\s*[\'"]([^\'"]+)[\'"]/', $content, $c);
                $cols = array_slice(array_unique($c[2]), 0, 15);
                $tables[$table] = $cols;
                if (count($tables) >= 20) {
                    break;
                }
            }
            if ($tables === []) {
                return '';
            }
            $parts = [];
            foreach ($tables as $table => $cols) {
                $parts[] = $table.' ('.implode(', ', $cols).')';
            }

            return 'Skema database ('.count($tables).' tabel): '.implode('; ', $parts).'.';
        });
    }

    public function apiInventory(): string
    {
        return $this->remember('api', function (): string {
            if (! $this->readable()) {
                return '';
            }
            $controllers = $this->basenames($this->glob('app/Http/Controllers/*.php'), '.php');
            if ($controllers === []) {
                return '';
            }

            return 'Controller yang sudah ada ('.count($controllers).'): '.implode(', ', array_slice($controllers, 0, self::MAX_ITEMS)).'.';
        });
    }

    /** Pelajaran dari feedback QA di eksekusi sebelumnya (project yang sama). */
    public function riskPatterns(int $projectId): string
    {
        return Cache::remember($this->cacheKey.':risks', self::CACHE_TTL, function () use ($projectId): string {
            try {
                $feedbacks = ExecutionMessage::query()
                    ->where('sender_slug', 'qa-engineer')
                    ->whereHas('run', fn ($q) => $q->whereHas('story', fn ($s) => $s->where('project_id', $projectId)))
                    ->latest('id')->limit(3)->pluck('content');
            } catch (\Throwable) {
                return '';
            }
            if ($feedbacks->isEmpty()) {
                return '';
            }
            $items = $feedbacks->map(fn ($c) => mb_substr(trim((string) $c), 0, 200))->implode(' | ');

            return 'Pola risiko dari QA sebelumnya: '.$items;
        });
    }

    private function readable(): bool
    {
        return is_string($this->workspacePath) && $this->workspacePath !== '' && is_dir($this->workspacePath);
    }

    private function remember(string $suffix, callable $build): string
    {
        $text = (string) Cache::remember($this->cacheKey.':'.$suffix, self::CACHE_TTL, $build);

        return mb_substr($text, 0, self::CONTEXT_LIMIT);
    }

    /** @return array<int, string> */
    private function glob(string $pattern): array
    {
        // PHP glob tidak mendukung ** rekursif; gabungkan pola dangkal + dalam.
        $patterns = [$pattern];
        if (str_contains($pattern, '**/')) {
            $patterns[] = str_replace('**/', '', $pattern);
        }
        $files = [];
        foreach ($patterns as $p) {
            $files = array_merge($files, glob($this->workspacePath.'/'.$p, GLOB_BRACE) ?: []);
        }

        return array_values(array_filter(array_unique($files), 'is_file'));
    }

    /** @return array<int, string> */
    private function basenames(array $files, string $stripSuffix): array
    {
        $names = [];
        foreach (array_slice($files, 0, self::MAX_ITEMS * 2) as $file) {
            $base = basename($file);
            if (str_ends_with($base, $stripSuffix)) {
                $base = substr($base, 0, -strlen($stripSuffix));
            }
            // Abaikan file tersembunyi / internal.
            if (! str_starts_with($base, '.')) {
                $names[] = $base;
            }
        }
        sort($names);

        return array_values(array_unique($names));
    }

    /** @return array<int, string> */
    private function relativePaths(array $files): array
    {
        $paths = [];
        foreach (array_slice($files, 0, self::MAX_ITEMS * 2) as $file) {
            $rel = ltrim(str_replace($this->workspacePath, '', $file), '/');
            if ($rel !== '' && ! str_starts_with($rel, '.') && ! str_contains($rel, '/.')) {
                $paths[] = $rel;
            }
        }
        sort($paths);

        return array_values(array_unique($paths));
    }

    private function readFile(string $file, int $maxBytes): ?string
    {
        if (! is_file($file) || filesize($file) > $maxBytes) {
            return null;
        }
        $content = @file_get_contents($file);

        return is_string($content) ? $content : null;
    }
}
