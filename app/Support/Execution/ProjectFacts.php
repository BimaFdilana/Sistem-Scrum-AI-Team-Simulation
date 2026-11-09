<?php

namespace App\Support\Execution;

use App\Models\Project;
use App\Support\Demo\DemoServerManager;
use App\Support\Projects\CliPhp;
use App\Support\Projects\ProjectEnvironment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PDO;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Fakta project yang dibaca langsung dari project, untuk prompt developer dan QA.
 *
 * Tanpa ini agen menebak nama tabel, kolom, dan rute (US-04 memakai kolom `kode`
 * padahal tabelnya berisi `kode_iku`). Skema dibaca dari database demo yang sudah
 * dimigrasi, rute dari `route:list` project itu sendiri.
 */
class ProjectFacts
{
    /** Tabel bawaan framework yang tidak relevan untuk fitur. */
    private const IGNORED_TABLES = [
        'migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions',
        'password_reset_tokens', 'password_resets', 'personal_access_tokens', 'telescope_entries',
        'telescope_entries_tags', 'telescope_monitoring', 'pulse_entries', 'pulse_aggregates', 'pulse_values',
    ];

    public function __construct(private readonly DemoServerManager $demo) {}

    /** Bagian prompt berisi skema dan rute; kosong bila tidak bisa dibaca. */
    public function section(Project $project): string
    {
        $root = $this->root($project);
        if ($root === null) {
            return '';
        }

        $database = $this->databasePath($project);
        $repo = rtrim((string) $project->workspace_path, '/');
        $index = $repo.'/.git/index';
        $key = 'project-facts:'.$project->id.':'.md5($root.'|'.$this->head($repo).'|'.(is_file($index) ? filemtime($index) : 0).'|'.(is_file($database) ? filemtime($database) : 0));

        return Cache::remember($key, now()->addMinutes(30), function () use ($project, $root): string {
            $schema = $this->schema($project, $root);
            $routes = $this->routes($root);
            if ($schema === '' && $routes === '') {
                return '';
            }

            return implode("\n\n", array_filter([
                'FAKTA PROJECT (dibaca langsung dari project ini, bukan tebakan). Pakai nama tabel, kolom, dan rute PERSIS seperti di bawah. '
                    .'Kolom atau tabel yang belum ada wajib dibuat lewat migrasi baru, jangan dianggap sudah ada. '
                    .'route(\'nama\') hanya boleh memakai nama rute yang ada di daftar (atau yang dibuat task ini). '
                    .'$request->validated() hanya ada pada FormRequest; pada Request biasa pakai $request->validate([...]).',
                $schema !== '' ? "Skema database saat ini (tabel: kolom tipe, * = wajib diisi):\n".$schema : '',
                $routes !== '' ? "Rute yang sudah ada (METHOD URI nama -> aksi [middleware]):\n".$routes : '',
            ]));
        });
    }

    /** Skema dari database demo; disiapkan dulu bila belum ada. */
    public function schema(Project $project, ?string $root = null): string
    {
        $root ??= $this->root($project);
        $database = $this->databasePath($project);

        if ((! is_file($database) || filesize($database) === 0) && $root !== null && is_file($root.'/vendor/autoload.php')) {
            $prepared = $this->demo->prepareDatabase($project);
            $database = $prepared['database'] ?: $database;
        }

        if (! is_file($database) || filesize($database) === 0) {
            return '';
        }

        try {
            $pdo = new PDO('sqlite:'.$database);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

            $lines = [];
            foreach ($tables as $table) {
                if (in_array($table, self::IGNORED_TABLES, true)) {
                    continue;
                }
                $columns = $pdo->query('PRAGMA table_info("'.str_replace('"', '""', $table).'")')->fetchAll(PDO::FETCH_ASSOC);
                $parts = array_map(function (array $column): string {
                    $type = strtolower((string) $column['type']) ?: 'any';
                    $required = (int) $column['notnull'] === 1 && $column['dflt_value'] === null && (int) $column['pk'] === 0;

                    return $column['name'].' '.$type.($required ? '*' : '');
                }, $columns);
                $lines[] = '- '.$table.': '.implode(', ', $parts);
            }

            return Str::limit(implode("\n", $lines), 6000, "\n- (dipotong)");
        } catch (Throwable) {
            return '';
        }
    }

    /** Daftar rute project (tanpa rute vendor). */
    public function routes(string $root): string
    {
        if (! is_file($root.'/artisan') || ! is_file($root.'/vendor/autoload.php')) {
            return '';
        }

        try {
            $process = new Process(
                [CliPhp::path(), 'artisan', 'route:list', '--json', '--except-vendor', '--no-interaction'],
                $root,
                ProjectEnvironment::scrubbed(),
                null,
                60
            );
            $process->run();
            $routes = json_decode($process->getOutput(), true);
            if (! $process->isSuccessful() || ! is_array($routes)) {
                return '';
            }

            $lines = array_map(function (array $route): string {
                $middleware = array_values(array_filter((array) ($route['middleware'] ?? []), fn ($m) => is_string($m) && ! str_contains($m, '\\')));
                $action = (string) ($route['action'] ?? '');
                $action = str_replace('App\\Http\\Controllers\\', '', $action);

                return str_replace('|HEAD', '', (string) ($route['method'] ?? ''))
                    .' /'.ltrim((string) ($route['uri'] ?? ''), '/')
                    .(! empty($route['name']) ? ' '.$route['name'] : '')
                    .' -> '.$action
                    .($middleware !== [] ? ' ['.implode(',', $middleware).']' : '');
            }, $routes);

            return Str::limit(implode("\n", $lines), 5000, "\n(dipotong)");
        } catch (Throwable) {
            return '';
        }
    }

    public function databasePath(Project $project): string
    {
        return storage_path('app/demo-db/project-'.$project->id.'.sqlite');
    }

    private function root(Project $project): ?string
    {
        $root = rtrim((string) $project->workspace_path, '/');
        $subdir = $project->profile?->subdir;
        if ($subdir) {
            $root .= '/'.trim($subdir, '/');
        }

        return $root !== '' && is_dir($root) ? $root : null;
    }

    private function head(string $root): string
    {
        $head = @file_get_contents($root.'/.git/HEAD');
        if (! is_string($head)) {
            return '';
        }
        $head = trim($head);
        if (str_starts_with($head, 'ref: ')) {
            $ref = @file_get_contents($root.'/.git/'.substr($head, 5));

            return is_string($ref) ? trim($ref) : $head;
        }

        return $head;
    }
}
