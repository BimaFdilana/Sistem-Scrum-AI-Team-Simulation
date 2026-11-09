<?php

namespace App\Support\Workspace;

use App\Models\Project;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Snapshot baseline sebelum run mode `direct`.
 *
 * Mode langsung mengedit baseline, jadi satu kesalahan bisa merusak seluruh
 * project (kasus US-02: baseline terhapus). Bundle git menyimpan seluruh
 * riwayat + file dalam satu berkas, dan bisa dipulihkan tanpa GitHub.
 */
class BaselineSnapshot
{
    public const KEEP_PER_PROJECT = 5;

    public function directory(): string
    {
        $dir = storage_path('app/private/baseline-backups');
        File::ensureDirectoryExists($dir, 0700, true);

        return $dir;
    }

    /** @return string|null path bundle, atau null bila baseline belum punya repo */
    public function backup(Project $project, string $label = 'manual'): ?string
    {
        $baseline = rtrim((string) $project->workspace_path, '/');
        if ($baseline === '' || ! is_dir($baseline.'/.git')) {
            return null;
        }

        $safeLabel = preg_replace('/[^A-Za-z0-9._-]/', '-', $label) ?: 'snapshot';
        $file = $this->directory().'/project-'.$project->id.'-'.$safeLabel.'-'.now()->format('Ymd-His').'.bundle';

        $process = new Process(
            ['git', 'bundle', 'create', $file, '--all'],
            $baseline,
            ['PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin']
        );
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($file)) {
            throw new RuntimeException('Gagal membuat snapshot baseline: '.trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        $this->prune($project);

        return $file;
    }

    /**
     * Pulihkan baseline dari bundle.
     *
     * Bila .git masih ada: fetch dari bundle lalu reset keras.
     * Bila direktori hilang: clone bundle ke lokasi baseline.
     */
    public function restore(Project $project, string $bundlePath): void
    {
        if (! is_file($bundlePath)) {
            throw new RuntimeException('Berkas snapshot tidak ditemukan: '.$bundlePath);
        }

        $baseline = rtrim((string) $project->workspace_path, '/');
        if ($baseline === '') {
            throw new RuntimeException('Project tidak punya workspace_path.');
        }

        if (is_dir($baseline.'/.git')) {
            // Cari ref pertama di bundle, lalu fetch ke ref sementara.
            $heads = new Process(['git', 'bundle', 'list-heads', $bundlePath], $baseline);
            $heads->run();
            if (! $heads->isSuccessful()) {
                throw new RuntimeException('Bundle tidak terbaca: '.trim($heads->getErrorOutput()));
            }

            $ref = null;
            foreach (preg_split('/\r?\n/', trim($heads->getOutput())) as $line) {
                $parts = preg_split('/\s+/', trim($line));
                if (isset($parts[1]) && str_starts_with($parts[1], 'refs/heads/')) {
                    $ref = $parts[1];
                    break;
                }
            }
            if ($ref === null) {
                throw new RuntimeException('Bundle tidak memuat branch apa pun.');
            }

            $fetch = new Process(['git', 'fetch', '--force', $bundlePath, $ref.':refs/heads/restore-tmp'], $baseline);
            $fetch->run();
            if (! $fetch->isSuccessful()) {
                throw new RuntimeException('Gagal fetch snapshot: '.trim($fetch->getErrorOutput()));
            }

            (new Process(['git', 'reset', '--hard', 'refs/heads/restore-tmp'], $baseline))->run();
            (new Process(['git', 'clean', '-fd'], $baseline))->run();
            (new Process(['git', 'branch', '-D', 'restore-tmp'], $baseline))->run();

            return;
        }

        $parent = dirname($baseline);
        File::ensureDirectoryExists($parent, 0770, true);
        $temp = $baseline.'-restore-'.uniqid();

        $process = new Process(['git', 'clone', $bundlePath, $temp]);
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Gagal clone snapshot: '.trim($process->getErrorOutput()));
        }

        if (is_dir($baseline)) {
            File::moveDirectory($baseline, $baseline.'-rusak-'.now()->format('Ymd-His'));
        }
        File::moveDirectory($temp, $baseline);
    }

    public function prune(Project $project): void
    {
        $files = glob($this->directory().'/project-'.$project->id.'-*.bundle') ?: [];
        sort($files);
        $excess = count($files) - self::KEEP_PER_PROJECT;
        for ($i = 0; $i < $excess; $i++) {
            @unlink($files[$i]);
        }
    }
}
