<?php

namespace App\Support\Execution;

use App\Models\ExecutionRun;
use App\Models\ExecutionTask;

/**
 * Menentukan pelaksana sebuah task. Sejak migrasi ke Antigravity CLI, semua
 * task dikerjakan agen developer Antigravity; nilai ini disimpan di kolom
 * `executor` task untuk riwayat.
 */
class ExecutorPlanner
{
    public const ANTIGRAVITY = 'antigravity';

    public function forTask(ExecutionRun $run, ExecutionTask $task): string
    {
        return self::ANTIGRAVITY;
    }
}
