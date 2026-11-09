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

    /** Awal teks temuan regresi: perilaku yang berhasil sebelum run ini kini gagal. */
    public const REGRESSION_PREFIX = 'REGRESI';

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

        return $this->probeAt($project, $root, $changedFiles, $mode);
    }

    /**
     * Uji jalan kode pada ref git tertentu (mis. titik awal run, atau cabang run yang
     * sedang tidak di-checkout) lewat worktree sementara; baseline tidak disentuh.
     *
     * @param  array<int, string>  $changedFiles
     * @return array{ran: bool, error: ?string, roles: array<int, ?string>, results: array<int, array<string, mixed>>}
     */
    public function runAtRef(Project $project, string $ref, array $changedFiles, string $mode = 'changed'): array
    {
        $baseline = rtrim((string) $project->workspace_path, '/');
        $subdir = $project->profile?->subdir ? '/'.trim($project->profile->subdir, '/') : '';
        if ($baseline === '' || ! is_dir($baseline.'/.git') || ! is_file($baseline.$subdir.'/vendor/autoload.php')) {
            return $this->notRun('Project belum siap dijalankan (git atau vendor/ belum ada).');
        }

        $tree = storage_path('app/smoke/wt-'.Str::uuid());
        $add = new Process(['git', 'worktree', 'add', '--detach', $tree, $ref], $baseline, ProjectEnvironment::scrubbed(), null, 60);
        $add->run();
        if (! $add->isSuccessful()) {
            return $this->notRun('Ref '.$ref.' tidak bisa disiapkan: '.Str::limit(trim($add->getErrorOutput()), 200));
        }

        try {
            $root = $tree.$subdir;
            // vendor/ dibagi dengan baseline; probe memuat kelas project dari root ini sendiri.
            @symlink($baseline.$subdir.'/vendor', $root.'/vendor');
            if (is_file($baseline.$subdir.'/.env')) {
                @copy($baseline.$subdir.'/.env', $root.'/.env');
            }
            foreach (['storage/framework/views', 'storage/framework/cache', 'storage/framework/sessions', 'storage/logs', 'bootstrap/cache'] as $dir) {
                File::ensureDirectoryExists($root.'/'.$dir);
            }

            return $this->probeAt($project, $root, $changedFiles, $mode);
        } finally {
            (new Process(['git', 'worktree', 'remove', '--force', $tree], $baseline, ProjectEnvironment::scrubbed(), null, 60))->run();
            File::deleteDirectory($tree);
        }
    }

    /**
     * Perilaku yang berhasil sebelum perubahan (base) tetapi gagal sesudahnya (branch).
     * Crash sudah ditangani findings(); di sini yang dicari adalah halaman atau form
     * yang dulu OK lalu ditolak validasi, ditolak akses, tidak ditemukan, atau hilang.
     *
     * @param  array{results: array<int, array<string, mixed>>}  $base
     * @param  array{results: array<int, array<string, mixed>>}  $branch
     * @return array<int, array{file: string, line: ?int, severity: string, instruction: string, verify: string}>
     */
    public function regressions(array $base, array $branch): array
    {
        $index = function (array $report): array {
            $map = [];
            foreach ($report['results'] ?? [] as $result) {
                $key = ($result['method'] ?? 'GET').' '.preg_replace('#/\d+(?=/|$)#', '/{id}', (string) ($result['uri'] ?? ''));
                $map[$key] ??= $result;
            }

            return $map;
        };

        $before = $index($base);
        $after = $index($branch);
        $findings = [];

        foreach ($before as $key => $old) {
            if (($old['kind'] ?? '') !== 'ok') {
                continue;
            }
            $new = $after[$key] ?? null;
            $kind = $new['kind'] ?? null;

            if ($new === null) {
                if (! str_starts_with($key, 'GET ')) {
                    continue; // form bisa saja pindah halaman; hanya halaman GET yang dianggap hilang
                }
                $what = 'tidak bisa dibuka lagi (rute hilang)';
            } elseif (in_array($kind, ['validation', 'denied', 'not_found'], true)) {
                $what = match ($kind) {
                    'validation' => 'kini ditolak validasi: '.implode('; ', array_slice((array) ($new['validation'] ?? []), 0, 3)),
                    'denied' => 'kini ditolak aksesnya (HTTP '.($new['status'] ?? '403').')',
                    default => 'kini tidak ditemukan (HTTP 404)',
                };
            } else {
                continue;
            }

            $file = (string) (($new['route_file'] ?? null) ?: ($old['route_file'] ?? null) ?: 'routes/web.php');
            $findings[] = [
                'file' => ltrim($file, '/'),
                'line' => null,
                'severity' => 'high',
                'instruction' => Str::limit(self::REGRESSION_PREFIX.' — '.$key.' berhasil sebelum run ini, '.$what
                    .'. Kembalikan perilaku lama; fitur baru story ini tidak boleh merusak fitur yang sudah ada (pakai rute/method baru bila perlu).', 400),
                'verify' => Str::limit('Jalankan lagi '.$key.' dengan data yang sama; hasilnya harus sama seperti sebelum run ini.', 200),
            ];
        }

        return $findings;
    }

    /**
     * @param  array<int, string>  $changedFiles
     * @return array{ran: bool, error: ?string, roles: array<int, ?string>, results: array<int, array<string, mixed>>}
     */
    private function probeAt(Project $project, string $root, array $changedFiles, string $mode): array
    {
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
