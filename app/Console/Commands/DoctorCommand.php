<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\ActiveModelService;
use App\Support\Workspace\WorktreeManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/**
 * Pemeriksaan kesiapan sebelum menjalankan story.
 *
 * Tujuan: kegagalan lingkungan (gateway mati, model tidak menjawab, OpenCode
 * tidak bisa menulis, baseline hilang, worker queue mati, disk penuh) diketahui
 * dalam hitungan detik — bukan setelah 30 menit menunggu task.
 */
class DoctorCommand extends Command
{
    protected $signature = 'scrumspace:doctor {--skip-opencode : Lewati uji tulis OpenCode} {--quick : Lewati uji yang memanggil model}';

    protected $description = 'Periksa kesiapan sistem: gateway, model, OpenCode, baseline, queue, disk, demo';

    private array $rows = [];

    public function handle(): int
    {
        $this->checkDatabase();
        $this->checkGateway();
        $this->checkModel();
        $this->checkOpenCode();
        $this->checkQueue();
        $this->checkBaselines();
        $this->checkResources();
        $this->checkDemo();

        $this->newLine();
        $this->table(['Pemeriksaan', 'Status', 'Keterangan'], $this->rows);

        $failed = collect($this->rows)->filter(fn (array $row) => $row[1] === 'GAGAL')->count();
        $warn = collect($this->rows)->filter(fn (array $row) => $row[1] === 'PERINGATAN')->count();

        if ($failed > 0) {
            $this->error($failed.' pemeriksaan gagal — perbaiki sebelum menjalankan story.');

            return self::FAILURE;
        }

        $this->info('Semua pemeriksaan lolos'.($warn > 0 ? ' ('.$warn.' peringatan).' : '.'));

        return self::SUCCESS;
    }

    private function record(string $name, string $status, string $note): void
    {
        $this->rows[] = [$name, $status, mb_substr($note, 0, 120)];
    }

    private function checkDatabase(): void
    {
        try {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'sqlite') {
                $mode = DB::selectOne('PRAGMA journal_mode')->journal_mode ?? '?';
                $busy = DB::selectOne('PRAGMA busy_timeout')->timeout ?? '?';
                $this->record('Database SQLite', 'OK', 'journal='.$mode.' busy_timeout='.$busy);
            } else {
                $this->record('Database', 'OK', $driver);
            }
        } catch (\Throwable $e) {
            $this->record('Database', 'GAGAL', $e->getMessage());
        }
    }

    private function checkGateway(): void
    {
        $base = rtrim((string) config('ai.cliproxy.base_url', 'http://127.0.0.1:8317'), '/');
        try {
            $response = Http::timeout(8)->withHeaders(['Authorization' => 'Bearer '.config('ai.cliproxy.api_key')])
                ->get($base.'/v1/models');
            if ($response->successful()) {
                $count = count((array) ($response->json('data') ?? []));
                $this->record('Gateway model', 'OK', $count.' model tersedia');
            } else {
                $this->record('Gateway model', 'GAGAL', 'HTTP '.$response->status());
            }
        } catch (\Throwable $e) {
            $this->record('Gateway model', 'GAGAL', $e->getMessage());
        }
    }

    private function checkModel(): void
    {
        $model = app(ActiveModelService::class)->current();
        if (! $model) {
            $this->record('Model aktif', 'GAGAL', 'Belum dipilih di menu AI Providers');

            return;
        }
        $this->record('Model aktif', 'OK', (string) $model);

        if ($this->option('quick')) {
            return;
        }

        try {
            $started = microtime(true);
            $reply = app(\App\Services\CliProxyApiClient::class)->chat((string) $model, 'Jawab satu kata.', 'ping', 30);
            $ms = (int) round((microtime(true) - $started) * 1000);
            if (is_string($reply) && trim($reply) !== '') {
                $this->record('Model menjawab', 'OK', $ms.' ms');
            } else {
                $this->record('Model menjawab', 'GAGAL', 'balasan kosong');
            }
        } catch (\Throwable $e) {
            $this->record('Model menjawab', 'GAGAL', $e->getMessage());
        }
    }

    private function checkOpenCode(): void
    {
        $binary = (string) config('ai.opencode.binary', '/usr/bin/opencode');
        if (! is_executable($binary)) {
            $this->record('OpenCode binary', 'GAGAL', 'tidak ada di '.$binary);

            return;
        }
        $this->record('OpenCode binary', 'OK', $binary);

        if ($this->option('skip-opencode') || $this->option('quick')) {
            return;
        }

        $dir = storage_path('app/private/doctor-'.uniqid());
        try {
            File::ensureDirectoryExists($dir, 0770, true);
            $git = new Process(['git', 'init', '-q'], $dir);
            $git->run();
            file_put_contents($dir.'/README.md', '# doctor');
            (new Process(['git', 'add', '.'], $dir))->run();
            (new Process(['git', '-c', 'user.email=doctor@local', '-c', 'user.name=doctor', 'commit', '-qm', 'init'], $dir))->run();

            $result = app(\App\Support\Execution\OpenCodeRunner::class)->run(
                $dir,
                'Buat file doctor.txt berisi tepat: ok. Gunakan tool tulis file.',
                180
            );
            $written = is_file($dir.'/doctor.txt') && trim((string) file_get_contents($dir.'/doctor.txt')) !== '';

            if ($written) {
                $this->record('OpenCode menulis file', 'OK', 'model '.app()->make(\App\Support\Execution\OpenCodeRunner::class)->model());
            } else {
                $this->record('OpenCode menulis file', 'GAGAL', 'exit='.(string) $result['exit'].' '.mb_substr((string) $result['output'], -80));
            }
        } catch (\Throwable $e) {
            $this->record('OpenCode menulis file', 'GAGAL', $e->getMessage());
        } finally {
            File::deleteDirectory($dir);
        }
    }

    private function checkQueue(): void
    {
        $pending = 0;
        try {
            $pending = DB::table('jobs')->count();
        } catch (\Throwable) {
            // tabel jobs tidak ada (sync queue)
        }

        $process = new Process(['systemctl', 'is-active', 'scrumspace-queue']);
        $process->run();
        $active = trim($process->getOutput()) === 'active';

        if ($active) {
            $this->record('Worker queue', 'OK', 'aktif · '.$pending.' job menunggu');
        } else {
            $this->record('Worker queue', $pending > 0 ? 'GAGAL' : 'PERINGATAN', 'tidak aktif · '.$pending.' job menunggu');
        }

        try {
            $stuck = \App\Models\ExecutionTask::query()
                ->whereIn('status', ['IN_PROGRESS', 'IN_REVIEW'])
                ->where('last_progress_at', '<', now()->subMinutes(20))
                ->count();
            if ($stuck > 0) {
                $this->record('Task macet', 'PERINGATAN', $stuck.' task tanpa progres > 20 menit');
            }
        } catch (\Throwable) {
            // skema lama
        }
    }

    private function checkBaselines(): void
    {
        $projects = Project::query()->get();
        if ($projects->isEmpty()) {
            $this->record('Baseline project', 'PERINGATAN', 'belum ada project');

            return;
        }

        $worktrees = new WorktreeManager;
        foreach ($projects as $project) {
            $path = (string) $project->workspace_path;
            if (! is_dir($path)) {
                $this->record('Baseline #'.$project->id, 'GAGAL', 'folder tidak ada: '.$path);
                continue;
            }
            try {
                $worktrees->ensureRepo($path);
                $dirty = count($worktrees->dirtyFiles($path));
                $this->record(
                    'Baseline #'.$project->id,
                    $dirty === 0 ? 'OK' : 'PERINGATAN',
                    $dirty === 0 ? 'bersih' : $dirty.' file belum di-commit'
                );
            } catch (\Throwable $e) {
                $this->record('Baseline #'.$project->id, 'GAGAL', $e->getMessage());
            }
        }
    }

    private function checkResources(): void
    {
        $free = @disk_free_space(base_path());
        if ($free !== false) {
            $gb = $free / 1024 / 1024 / 1024;
            $this->record('Disk bebas', $gb >= 0.8 ? 'OK' : 'GAGAL', number_format($gb, 2).' GB');
        }

        $mem = @file_get_contents('/proc/meminfo');
        if (is_string($mem) && preg_match('/MemAvailable:\\s+(\\d+) kB/', $mem, $m)) {
            $mb = (int) round(((int) $m[1]) / 1024);
            $this->record('RAM tersedia', $mb >= 250 ? 'OK' : 'GAGAL', $mb.' MB');
        }
    }

    private function checkDemo(): void
    {
        try {
            $problem = \App\Models\DemoServer::query()->where('status', 'RUNNING')->count();
            $this->record('Demo berjalan', 'OK', $problem.' server demo aktif');
        } catch (\Throwable $e) {
            $this->record('Demo berjalan', 'PERINGATAN', $e->getMessage());
        }
    }
}
