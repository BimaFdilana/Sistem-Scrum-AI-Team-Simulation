<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Adapter eksekusi via Hermes CLI.
 *
 * Menggantikan loop tool internal (AgentToolRunner) untuk satu task:
 * Hermes dipanggil satu query non-interaktif (`hermes chat --query-file`)
 * dengan cwd = worktree task, bekerja langsung pada file worktree, lalu
 * berhenti dengan ringkasan. Job pemanggil (ExecuteExecutionTaskJob)
 * tetap yang melakukan commit + diff + kirim ke QA, persis seperti
 * jalur lama — sehingga gerbang permission, QA, merge, dan demo
 * tidak berubah sama sekali.
 *
 * Model eksekusi diambil PER ROLE dari menu Konfigurasi Agent
 * (keputusan Bima 2026-10-31, diperketat 20:57 WIB): HANYA model yang
 * dipilih PO secara eksplisit yang boleh dipakai — snapshot
 * AgentConfiguration terverifikasi DAN modelnya masih ada di daftar
 * model gateway saat ini. TIDAK ADA fallback diam-diam apa pun:
 * bukan HERMES_MODEL, bukan pemetaan otomatis per provider.
 * Bila role belum memilih (tidak ada snapshot, belum terverifikasi,
 * atau modelnya tidak lagi tersedia), Hermes TIDAK dipanggil sama
 * sekali dan exception dilempar dengan pesan yang mengarah ke aksi
 * PO — pesan itu sampai ke last_error task + notifikasi PO lewat
 * failRun di job pemanggil. Provider/base_url/max_turns tetap dari
 * config; hanya sumber model yang ketat per role.
 *
 * Bentuk kembalian SAMA dengan AgentToolRunner::run() agar job bisa
 * memakai salah satu driver tanpa mengubah alur berikutnya.
 */
class HermesTaskRunner
{
    /**
     * @param  array{execution_run_id?:int|null,execution_task_id?:int|null,source?:string|null}  $context
     * @return array{files: string[], summary: string}
     */
    public function run(string $slug, string $worktree, string $taskPrompt, bool $allowDelete, array $context = []): array
    {
        $real = realpath($worktree);
        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException('Worktree tidak valid.');
        }

        $cfg = config('ai.hermes', []);
        $binary = $cfg['binary'] ?? 'hermes';
        $model = $this->strictModelForRole($slug);
        $provider = $cfg['provider'] ?? 'custom';
        $baseUrl = $cfg['base_url'] ?? 'http://127.0.0.1:8317/v1';
        $apiKey = config('ai.cliproxy.api_key');
        if (! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException('Kunci gateway tidak dikonfigurasi (CLIPROXY_API_KEY).');
        }
        $maxTurns = (int) ($cfg['max_turns'] ?? 50);
        $timeout = (int) ($cfg['timeout'] ?? 1500);

        // Prompt lewat file (bukan argumen shell) agar teks task apa pun
        // tiba verbatim — pola yang sama dipakai `hermes chat --query-file`.
        $promptFile = tempnam(sys_get_temp_dir(), 'hermes-task-');
        if ($promptFile === false) {
            throw new RuntimeException('Gagal membuat file prompt sementara.');
        }
        try {
            file_put_contents($promptFile, $this->buildPrompt($taskPrompt, $allowDelete));
            // WAJIB: tempnam() membuat file 0600 milik www-data, sementara
            // Hermes berjalan sebagai sidik (lewat wrapper sudoers) — tanpa
            // ini Hermes mati exit 2 "cannot read --query file" sebelum
            // bekerja (Run #3, 2026-10-31). Isi prompt hanya teks task
            // (tanpa secret — kunci lewat argv), jadi 0644 aman; file
            // tetap dihapus di finally setelah jalan.
            chmod($promptFile, 0644);

            $command = [
                $binary, 'chat', '--query-file', $promptFile,
                '--provider', $provider,
                '--base-url', $baseUrl,
                '--api-key', $apiKey,
                '--model', $model,
                '--max-turns', (string) $maxTurns,
            ];
            $started = microtime(true);
            $process = new Process($command, $real, null, null, $timeout);
            $process->run();
            $duration = (int) round(microtime(true) - $started);

            $output = trim($process->getOutput());
            if (! $process->isSuccessful()) {
                Log::warning('Hermes task failed', [
                    'execution_run_id' => $context['execution_run_id'] ?? null,
                    'execution_task_id' => $context['execution_task_id'] ?? null,
                    'model' => $model,
                    'exit_code' => $process->getExitCode(),
                    'output_excerpt' => mb_substr($output, 0, 2000),
                    'error_excerpt' => mb_substr(trim($process->getErrorOutput()), 0, 2000),
                    'duration_seconds' => $duration,
                ]);
                throw new RuntimeException('Hermes gagal mengerjakan task (exit '.$process->getExitCode().').');
            }

            $files = $this->changedFiles($real);
            if ($files === []) {
                throw new RuntimeException('Hermes tidak mengubah file apa pun di worktree.');
            }
            $this->validateChanges($real, $files, $allowDelete);

            $summary = $output === '' ? 'Mengubah '.count($files).' file.' : mb_substr($output, 0, 4000);
            Log::info('Hermes task completed', [
                'execution_run_id' => $context['execution_run_id'] ?? null,
                'execution_task_id' => $context['execution_task_id'] ?? null,
                'model' => $model,
                'files' => $files,
                'duration_seconds' => $duration,
            ]);

            return ['files' => $files, 'summary' => $summary];
        } finally {
            @unlink($promptFile);
        }
    }

    /**
     * Model untuk satu role, KETAT sesuai aturan Bima (2026-10-31):
     * hanya snapshot AgentConfiguration terverifikasi yang modelnya
     * masih ada di daftar model gateway saat ini yang boleh dipakai.
     * Slug assignee task (frontend-developer/backend-developer) sudah
     * sama dengan slug role di agent_role_settings, jadi tidak ada
     * pemetaan tambahan. Resolusi didelegasikan ke StrictModelResolver
     * (satu-satunya sumber kebenaran model per role). Bila syarat
     * tidak terpenuhi, lempar exception SEBELUM Hermes dipanggil
     * (tidak ada token terpakai, tidak ada perubahan worktree); job
     * pemanggil mencatat pesan ini ke last_error task + notifikasi PO.
     */
    private function strictModelForRole(string $slug): string
    {
        // Delegasi ke resolver bersama (aturan tanpa-default): hanya
        // snapshot menu terverifikasi yang modelnya masih ada di daftar
        // gateway saat ini; bila tidak ada, require() melempar
        // ModelNotChosenException dengan pesan aksi PO — Hermes tidak
        // pernah dipanggil dan tidak ada fallback ke model lain.
        return app(StrictModelResolver::class)->require($slug);
    }

    private function buildPrompt(string $taskPrompt, bool $allowDelete): string
    {
        return implode("\n\n", array_filter([
            $taskPrompt,
            'ATURAN KERJA (wajib dipatuhi):',
            'Kerjakan HANYA dengan tool file (baca, ubah, buat file) di direktori kerja ini.',
            'JANGAN menjalankan perintah shell, server, instalasi paket, commit, push, atau merge.',
            'JANGAN menyentuh file di luar direktori kerja ini.',
            $allowDelete
                ? 'Menghapus file di dalam direktori kerja diizinkan bila task memintanya.'
                : 'DILARANG menghapus file. Lakukan hanya perubahan minimal yang aman.',
            'Akhiri dengan ringkasan perubahan: file apa saja yang diubah dan apa yang diubah.',
        ]));
    }

    /**
     * Daftar file yang berubah di worktree, dari git status.
     * Path relatif terhadap root worktree (porcelain tidak pernah
     * mengeluarkan path absolut), sehingga tidak bisa lolos ke luar.
     *
     * @return string[]
     */
    private function changedFiles(string $worktree): array
    {
        $process = new Process(['git', '-C', $worktree, 'status', '--porcelain'], $worktree, null, null, 60);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Gagal membaca status git worktree.');
        }
        $files = [];
        // JANGAN trim() seluruh output: baris pertama porcelain diawali
        // spasi status (mis. " M file"), dan trim akan memenggalnya
        // sehingga substr(3) kehilangan huruf pertama path.
        foreach (explode("\n", $process->getOutput()) as $line) {
            if ($line === '') {
                continue;
            }
            // Format: XY<spasi>path, atau XY<spasi>lama -> baru untuk rename.
            $path = trim(substr($line, 3));
            if (str_contains($path, ' -> ')) {
                $path = trim(substr($path, (int) strrpos($path, ' -> ') + 4));
            }
            // Hapus tanda kutip git untuk path berkarakter khusus.
            $path = trim($path, '"');
            if ($path !== '' && ! str_contains($path, '..')) {
                $files[] = $path;
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * Validasi pasca-kerja, menggantikan php -l per edit di loop lama:
     * file PHP yang berubah harus lolos lint, dan penghapusan hanya
     * boleh terjadi bila diizinkan (bukan perintah perbaikan).
     */
    private function validateChanges(string $worktree, array $files, bool $allowDelete): void
    {
        $status = new Process(['git', '-C', $worktree, 'status', '--porcelain'], $worktree, null, null, 60);
        $status->run();
        if ($status->isSuccessful() && ! $allowDelete) {
            foreach (explode("\n", $status->getOutput()) as $line) {
                if ($line !== '' && ($line[0] === 'D' || $line[1] === 'D')) {
                    throw new RuntimeException('Hermes menghapus file padahal penghapusan tidak diizinkan.');
                }
            }
        }
        foreach ($files as $file) {
            if (! str_ends_with($file, '.php')) {
                continue;
            }
            $lint = new Process([PHP_BINARY, '-l', $worktree.DIRECTORY_SEPARATOR.$file], $worktree, null, null, 60);
            $lint->run();
            if (! $lint->isSuccessful()) {
                throw new RuntimeException('File PHP gagal lint: '.$file.' — '.trim($lint->getErrorOutput() ?: $lint->getOutput()));
            }
        }
    }
}
