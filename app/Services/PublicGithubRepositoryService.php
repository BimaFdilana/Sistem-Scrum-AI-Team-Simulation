<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\File;
use RuntimeException;

class PublicGithubRepositoryService
{
    private const MAX_COMMAND_SECONDS = 120;

    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        $path = $parts['path'] ?? '';

        if (($parts['scheme'] ?? null) !== 'https'
            || strtolower($parts['host'] ?? '') !== 'github.com'
            || isset($parts['user'], $parts['pass'], $parts['port'], $parts['query'], $parts['fragment'])
            || ! preg_match('#^/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$#', $path, $matches)) {
            throw new RuntimeException('URL harus berupa repository public GitHub HTTPS tanpa credential atau parameter tambahan.');
        }

        return 'https://github.com/'.$matches[1].'/'.$matches[2].'.git';
    }

    public function import(Project $project): Project
    {
        $url = self::normalizeUrl((string) $project->source_repository_url);
        $root = storage_path('app/private/project-workspaces/'.$project->id.'/baseline');
        $parent = dirname($root);
        File::ensureDirectoryExists($parent, 0700);

        if (File::exists($root)) {
            File::deleteDirectory($root);
        }

        $headOutput = $this->run(['git', '-c', 'credential.helper=', 'ls-remote', '--symref', $url, 'HEAD'], base_path());
        $defaultBranch = $this->extractDefaultBranch($headOutput);
        $branch = $project->source_branch ?: $defaultBranch;
        if (! $branch || ! preg_match('/^[A-Za-z0-9._\/-]+$/', $branch) || str_contains($branch, '..')) {
            throw new RuntimeException('Branch GitHub tidak valid.');
        }

        $this->run([
            'git', '-c', 'credential.helper=', 'clone', '--depth', '1', '--no-tags', '--branch', $branch, $url, $root,
        ], base_path());
        $commit = trim($this->run(['git', '-C', $root, 'rev-parse', 'HEAD'], base_path()));
        if (! preg_match('/^[0-9a-f]{40}$/i', $commit)) {
            throw new RuntimeException('Commit baseline GitHub tidak valid.');
        }

        $this->assertLaravelProject($root);

        return tap($project->fresh(), function (Project $project) use ($url, $branch, $commit, $root): void {
            $project->forceFill([
                'source_repository_url' => $url,
                'source_branch' => $branch,
                'source_commit_sha' => strtolower($commit),
                'workspace_path' => $root,
                'stack' => 'Laravel',
                'status' => 'BASELINE_READY',
                'source_error' => null,
            ])->save();
        });
    }

    private function assertLaravelProject(string $root): void
    {
        foreach (['artisan', 'composer.json', 'app', 'bootstrap', 'routes'] as $required) {
            if (! File::exists($root.'/'.$required)) {
                throw new RuntimeException('Repository bukan project Laravel yang valid: komponen '.$required.' tidak ditemukan.');
            }
        }

        $composer = json_decode((string) File::get($root.'/composer.json'), true);
        $requires = array_merge($composer['require'] ?? [], $composer['require-dev'] ?? []);
        if (! is_array($composer) || ! isset($requires['laravel/framework'])) {
            throw new RuntimeException('Repository bukan project Laravel: laravel/framework tidak ditemukan di composer.json.');
        }
    }

    private function extractDefaultBranch(string $output): ?string
    {
        if (preg_match('/ref: refs\/heads\/([^\s]+)\s+HEAD/', $output, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function run(array $command, string $cwd): string
    {
        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptor, $pipes, $cwd, [
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => '/dev/null',
        ]);
        if (! is_resource($process)) {
            throw new RuntimeException('Git command tidak dapat dijalankan.');
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
                throw new RuntimeException('GitHub repository melebihi batas waktu import.');
            }
            usleep(100000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException('Repository GitHub tidak dapat diakses sebagai repository public atau command gagal.');
        }

        return $stdout.$stderr;
    }
}
