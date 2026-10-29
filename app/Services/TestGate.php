<?php

namespace App\Services;

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Gerbang tes QA: dijalankan SEBELUM review QA, dan approval QA
 * TIDAK bisa meng-override gerbang yang gagal.
 *
 * - `php -l` wajib untuk setiap file PHP yang berubah (menangkap
 *   syntax error sebelum QA membuang satu putaran revisi untuknya).
 * - Perintah tes project bersifat opsional per project (default MATI,
 *   kolom projects.test_command null). Bila PO mengisinya, perintah
 *   itu dijalankan di worktree task; gagal = gerbang gagal.
 *
 * Mengembalikan null bila lolos, atau string penjelasan bila gagal.
 * Gerbang TIDAK melempar: pemanggil (ReviewExecutionTaskJob) yang
 * memutuskan kirim revisi / gagal permanen.
 */
class TestGate
{
    public const TEST_TIMEOUT_SECONDS = 300;

    public const MAX_OUTPUT_CHARS = 4000;

    /**
     * @param  string[]  $changedFiles  path relatif terhadap worktree
     */
    public function run(string $worktree, array $changedFiles, ?string $testCommand): ?string
    {
        $failures = [];
        foreach ($changedFiles as $file) {
            if (! str_ends_with($file, '.php')) {
                continue;
            }
            $target = $worktree.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file);
            if (! is_file($target)) {
                continue; // file dihapus pada task ini: tidak perlu lint
            }
            $process = new Process([PHP_BINARY, '-l', $target]);
            $process->run();
            if (! $process->isSuccessful()) {
                $failures[] = 'php -l gagal pada '.$file.': '.trim($process->getErrorOutput() ?: $process->getOutput());
            }
        }
        if ($failures !== []) {
            return 'Gerbang php -l gagal: '.implode(' | ', $failures);
        }

        $testCommand = $testCommand !== null ? trim($testCommand) : '';
        if ($testCommand === '') {
            return null;
        }
        $process = Process::fromShellCommandline($testCommand, $worktree);
        $process->setTimeout(self::TEST_TIMEOUT_SECONDS);
        $process->run();
        if (! $process->isSuccessful()) {
            $output = trim($process->getOutput()."\n".$process->getErrorOutput());

            return 'Perintah tes project gagal ('.$testCommand.'): '.Str::limit($output, self::MAX_OUTPUT_CHARS);
        }

        return null;
    }
}
