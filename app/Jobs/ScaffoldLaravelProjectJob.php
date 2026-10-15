<?php

namespace App\Jobs;

use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Membuat kerangka Laravel asli (composer create-project) untuk project baru,
 * simetris dengan ImportGithubProjectJob untuk project existing.
 * Workspace: storage/app/private/project-workspaces/{id}/baseline
 */
class ScaffoldLaravelProjectJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MAX_COMMAND_SECONDS = 600;

    public int $tries = 1;

    public int $timeout = 660;

    public int $uniqueFor = 86400;

    public function __construct(public readonly int $projectId) {}

    public function uniqueId(): string
    {
        return 'laravel-project-scaffold-'.$this->projectId;
    }

    public function handle(): void
    {
        $project = Project::query()->find($this->projectId);
        if (! $project || $project->status !== 'SCAFFOLDING') {
            return;
        }

        $root = storage_path('app/private/project-workspaces/'.$project->id.'/baseline');
        $parent = dirname($root);
        File::ensureDirectoryExists($parent, 0700);

        if (File::exists($root)) {
            File::deleteDirectory($root);
        }

        try {
            $this->run(['composer', 'create-project', 'laravel/laravel', $root, '--no-interaction', '--prefer-dist', '--no-progress'], base_path());

            foreach (['artisan', 'composer.json', 'app', 'bootstrap', 'routes'] as $required) {
                if (! File::exists($root.'/'.$required)) {
                    throw new RuntimeException('Scaffold Laravel gagal: komponen '.$required.' tidak ditemukan.');
                }
            }

            $project->forceFill([
                'workspace_path' => $root,
                'stack' => 'Laravel',
                'status' => 'BASELINE_READY',
                'source_error' => null,
            ])->save();

            $this->syncToGithub($project);
        } catch (Throwable $exception) {
            $project->forceFill([
                'status' => 'SOURCE_REJECTED',
                'source_error' => mb_substr($exception->getMessage(), 0, 255),
            ])->save();
            throw $exception;
        }
    }

    /**
     * Fase 2: buatkan repo GitHub + push baseline. Graceful: bila GitHub
     * belum terhubung atau gagal, project tetap BASELINE_READY (tanpa GitHub).
     */
    protected function syncToGithub(Project $project): void
    {
        try {
            $service = app(\App\Services\GithubProjectService::class);
            if (! $service->isConnected()) {
                Log::info('GitHub belum terhubung — project baru dilewati tanpa sinkronisasi', [
                    'project_id' => $project->id,
                ]);

                return;
            }

            $repoName = \Illuminate\Support\Str::slug($project->name, '-');
            if ($repoName === '') {
                $repoName = 'project-'.$project->id;
            }

            $result = $service->ensureRepoForNewProject($project->fresh(), $repoName);
            $service->pushBaseline($project->fresh(), $result['owner'], $result['repo']);

            $project->forceFill([
                'github_owner' => $result['owner'],
                'github_repo' => $result['repo'],
            ])->save();
        } catch (Throwable $exception) {
            // Jangan gagalkan project — catat saja, baseline lokal tetap valid.
            Log::warning('Sinkronisasi GitHub project baru gagal (project tetap jalan tanpa GitHub)', [
                'project_id' => $project->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $project = Project::query()->find($this->projectId);
        if (! $project || $project->status === 'BASELINE_READY') {
            return;
        }

        $project->forceFill([
            'status' => 'SOURCE_REJECTED',
            'source_error' => mb_substr($exception?->getMessage() ?? 'Scaffold Laravel gagal.', 0, 255),
        ])->save();
    }

    private function run(array $command, string $cwd): string
    {
        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        // proc_open menimpa environment: teruskan PATH, HOME, dan proxy agar
        // composer tetap ditemukan dan bisa mengunduh paket lewat proxy bila ada.
        $env = [
            'COMPOSER_NO_INTERACTION' => '1',
            // Worker dapat berjalan sebagai root (VPS/VM); izinkan eksplisit.
            'COMPOSER_ALLOW_SUPERUSER' => '1',
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
        ];
        foreach (['HOME', 'http_proxy', 'https_proxy', 'HTTP_PROXY', 'HTTPS_PROXY', 'no_proxy', 'NO_PROXY'] as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $env[$key] = $value;
            }
        }
        $process = proc_open($command, $descriptor, $pipes, $cwd, $env);
        if (! is_resource($process)) {
            throw new RuntimeException('Composer tidak dapat dijalankan di server.');
        }

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $started = microtime(true);
        $stdout = '';
        $stderr = '';
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (! $status['running']) {
                break;
            }
            if (microtime(true) - $started > self::MAX_COMMAND_SECONDS) {
                proc_terminate($process, 9);
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }
                proc_close($process);
                throw new RuntimeException('Scaffold Laravel melebihi batas waktu (10 menit).');
            }
            usleep(100000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException('Composer create-project gagal: '.mb_substr(trim($stderr ?: $stdout), -200));
        }

        return $stdout.$stderr;
    }
}
