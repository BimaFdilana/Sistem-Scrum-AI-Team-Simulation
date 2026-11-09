<?php

namespace App\Console\Commands;

use App\Models\ExecutionRun;
use Illuminate\Console\Command;

/**
 * Ubah kebijakan pelaksana sebuah run tanpa menyentuh .env.
 *
 * auto     = BE/FE ke OpenCode, sisanya Hermes (default, ada fallback)
 * hermes   = semua lewat Hermes
 * opencode = BE/FE wajib OpenCode (kegagalan terlihat, tanpa fallback)
 * ab       = selang-seling untuk perbandingan
 */
class SetRunExecutorPolicy extends Command
{
    protected $signature = 'runs:policy {run : id run} {policy : auto|hermes|opencode|ab}';

    protected $description = 'Atur pelaksana task untuk satu run (auto/hermes/opencode/ab)';

    public function handle(): int
    {
        $policy = (string) $this->argument('policy');
        if (! in_array($policy, ['auto', 'hermes', 'opencode', 'ab'], true)) {
            $this->error('Policy harus salah satu: auto, hermes, opencode, ab.');

            return self::FAILURE;
        }

        $run = ExecutionRun::query()->find((int) $this->argument('run'));
        if (! $run) {
            $this->error('Run tidak ditemukan.');

            return self::FAILURE;
        }

        $run->forceFill(['executor_policy' => $policy])->save();
        $this->info('Run #'.$run->id.' memakai policy '.$policy.'.');

        return self::SUCCESS;
    }
}
