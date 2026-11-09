<?php

namespace App\Support\Execution;

use App\Models\Project;
use App\Support\Demo\DemoServerManager;
use App\Support\Projects\CliPhp;
use App\Support\Projects\ProjectEnvironment;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Uji jalan nyata: project dijalankan dengan salinan database demo, lalu halaman
 * yang berubah dibuka sebagai pengguna berperan (admin dulu) dan form di dalamnya
 * dikirim dengan data contoh. Crash (HTTP 500/exception) menjadi temuan yang
 * wajib diperbaiki developer; QA tidak lagi bisa menyetujui kode yang tidak jalan.
 *
 * Skrip penguji ada di resources/smoke/probe.php dan berjalan di proses terpisah
 * di dalam project target. Database demo yang asli tidak pernah disentuh.
 */
class RuntimeSmoke
{
    public const MARKER = '@@SCRUMSPACE_SMOKE@@';

    /** Awal teks temuan dari uji jalan (dipakai untuk mengenali dan menutupnya). */
    public const FINDING_PREFIX = 'UJI JALAN GAGAL';

    public function __construct(private readonly DemoServerManager $demo) {}

    /**
     * @param  array<int, string>  $changedFiles
     * @return array{ran: bool, error: ?string, roles: array<int, ?string>, results: array<int, array<string, mixed>>}
     */
    public function run(Project $project, array $changedFiles, string $mode = 'changed'): array
    {
        $root = rtrim((string) $project->workspace_path, '/');
        if ($project->profile?->subdir) {
            $root .= '/'.trim($project->profile->subdir, '/');
        }

        if ($root === '' || ! is_file($root.'/artisan') || ! is_file($root.'/vendor/autoload.php')) {
            return $this->notRun('Project belum siap dijalankan (artisan atau vendor/ belum ada).');
        }

        $source = storage_path('app/demo-db/project-'.$project->id.'.sqlite');
        if (! is_file($source) || filesize($source) === 0) {
            $prepared = $this->demo->prepareDatabase($project);
            if (! $prepared['ok']) {
                return $this->notRun($prepared['message']);
            }
            $source = $prepared['database'];
        }

        $workdir = storage_path('app/smoke/'.Str::uuid());
        File::ensureDirectoryExists($workdir);

        try {
            $database = $workdir.'/database.sqlite';
            File::copy($source, $database);

            $env = array_merge(ProjectEnvironment::scrubbed(), [
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $database,
                'DB_FOREIGN_KEYS' => 'true',
                'APP_ENV' => 'testing',
                'APP_DEBUG' => 'true',
                'SESSION_DRIVER' => 'array',
                'CACHE_STORE' => 'array',
                'CACHE_DRIVER' => 'array',
                'QUEUE_CONNECTION' => 'sync',
                'MAIL_MAILER' => 'array',
                'BROADCAST_CONNECTION' => 'null',
                'LOG_CHANNEL' => 'null',
            ]);

            // Migrasi baru dari run ini harus jalan; kalau gagal, itu sendiri temuan.
            $migrate = new Process([CliPhp::path(), 'artisan', 'migrate', '--force', '--no-interaction'], $root, $env, null, 180);
            $migrate->run();
            if (! $migrate->isSuccessful()) {
                $output = $migrate->getOutput().$migrate->getErrorOutput();
                $file = preg_match_all('/(\d{4}_\d{2}_\d{2}_\d{6}_[\w]+)/', $output, $m) ? 'database/migrations/'.end($m[1]).'.php' : null;

                return [
                    'ran' => true,
                    'error' => null,
                    'roles' => [],
                    'results' => [[
                        'kind' => 'crash',
                        'method' => 'MIGRATE',
                        'uri' => 'php artisan migrate',
                        'status' => null,
                        'error' => 'Migrasi gagal: '.Str::limit(trim(preg_replace('/\s+/', ' ', $output)), 600),
                        'file' => $file,
                        'line' => null,
                    ]],
                ];
            }

            $config = $workdir.'/config.json';
            File::put($config, json_encode([
                'root' => $root,
                'mode' => $mode === 'all' ? 'all' : 'changed',
                'files' => array_values($changedFiles),
            ], JSON_UNESCAPED_SLASHES));

            $probe = new Process(
                [CliPhp::path(), resource_path('smoke/probe.php'), $config],
                $root,
                $env,
                null,
                (int) config('execution.smoke_timeout', 240)
            );
            $probe->run();

            $parts = explode(self::MARKER, $probe->getOutput());
            $data = isset($parts[1]) ? json_decode($parts[1], true) : null;
            if (! is_array($data)) {
                return $this->notRun('Penguji tidak menghasilkan laporan: '.Str::limit(trim($probe->getErrorOutput().' '.$parts[0]), 400));
            }

            return [
                'ran' => true,
                'error' => null,
                'roles' => (array) ($data['roles'] ?? []),
                'results' => array_values(array_filter((array) ($data['results'] ?? []), 'is_array')),
            ];
        } catch (Throwable $exception) {
            return $this->notRun('Uji jalan gagal dijalankan: '.Str::limit($exception->getMessage(), 300));
        } finally {
            File::deleteDirectory($workdir);
        }
    }

    /**
     * @param  array{results: array<int, array<string, mixed>>}  $report
     * @return array<int, array<string, mixed>>
     */
    public function crashes(array $report): array
    {
        return array_values(array_filter($report['results'] ?? [], fn (array $r) => ($r['kind'] ?? '') === 'crash'));
    }

    /**
     * Crash dikelompokkan per penyebab (file + pesan), lalu dijadikan temuan.
     *
     * @param  array{results: array<int, array<string, mixed>>}  $report
     * @param  array<int, string>  $changedFiles
     * @return array<int, array{file: string, line: ?int, severity: string, instruction: string, verify: string}>
     */
    public function findings(array $report, array $changedFiles): array
    {
        $groups = [];
        foreach ($this->crashes($report) as $crash) {
            $file = (string) ($crash['file'] ?? '') ?: (string) ($crash['route_file'] ?? '') ?: ($changedFiles[0] ?? 'routes/web.php');
            $error = (string) ($crash['error'] ?? 'HTTP '.($crash['status'] ?? '500'));
            $key = $file.'|'.$error;
            $groups[$key] ??= ['file' => $file, 'line' => isset($crash['line']) ? (int) $crash['line'] : null, 'error' => $error, 'requests' => []];
            $groups[$key]['requests'][] = trim(($crash['method'] ?? 'GET').' '.($crash['uri'] ?? '')).(! empty($crash['as_role']) ? ' (peran '.$crash['as_role'].')' : '');
        }

        return array_values(array_map(function (array $group): array {
            $requests = array_slice(array_unique($group['requests']), 0, 6);

            return [
                'file' => ltrim($group['file'], '/'),
                'line' => $group['line'],
                'severity' => 'high',
                'instruction' => Str::limit(self::FINDING_PREFIX.' — '.implode(', ', $requests).' menghasilkan error: '.$group['error'].'. Perbaiki penyebabnya di file ini (sesuaikan dengan skema dan rute yang ada), jangan menyembunyikan error.', 400),
                'verify' => Str::limit('Buka lagi '.implode(', ', $requests).'; harus tanpa error 500.', 200),
            ];
        }, $groups));
    }

    /** Ringkasan untuk QA dan PO. */
    public function summary(array $report): string
    {
        if (! ($report['ran'] ?? false)) {
            return 'Uji jalan tidak dijalankan: '.($report['error'] ?? 'alasan tidak diketahui');
        }

        $results = $report['results'] ?? [];
        if ($results === []) {
            return 'Uji jalan: tidak ada halaman yang terkait perubahan ini untuk dibuka.';
        }

        $lines = array_map(function (array $r): string {
            $label = match ($r['kind'] ?? '') {
                'ok' => 'OK',
                'crash' => 'ERROR',
                'validation' => 'VALIDASI',
                'denied' => 'DITOLAK',
                'not_found' => 'TIDAK DITEMUKAN',
                'skipped' => 'DILEWATI',
                default => strtoupper((string) ($r['kind'] ?? '?')),
            };
            $line = $label.' '.($r['method'] ?? '').' '.($r['uri'] ?? '').(isset($r['status']) ? ' → HTTP '.$r['status'] : '');
            if (! empty($r['as_role'])) {
                $line .= ' (peran '.$r['as_role'].')';
            }
            if (($r['kind'] ?? '') === 'crash' && ! empty($r['error'])) {
                $line .= ': '.Str::limit((string) $r['error'], 200);
            }
            if (! empty($r['validation'])) {
                $line .= ' — pesan validasi: '.Str::limit(implode('; ', (array) $r['validation']), 200);
            }
            if (! empty($r['reason'])) {
                $line .= ' ('.$r['reason'].')';
            }

            return '- '.$line;
        }, array_slice($results, 0, 40));

        $crashes = count($this->crashes($report));

        return 'Uji jalan nyata ('.count($results).' permintaan, '.$crashes.' error):'."\n".implode("\n", $lines);
    }

    /** @return array{ran: bool, error: ?string, roles: array<int, ?string>, results: array<int, array<string, mixed>>} */
    private function notRun(string $reason): array
    {
        return ['ran' => false, 'error' => $reason, 'roles' => [], 'results' => []];
    }
}
