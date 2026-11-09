<?php

namespace App\Support\Execution;

use App\Models\ExecutionRun;
use App\Models\ExecutionTask;

/**
 * Menentukan pelaksana sebuah task tanpa menyalakan/mematikan env manual.
 *
 * Urutan keputusan:
 *  1. executor_override pada task (dipakai fallback otomatis);
 *  2. executor_policy pada run (auto | hermes | opencode | ab);
 *  3. kill-switch global OPENCODE_ENABLED;
 *  4. peran task harus ada di OPENCODE_ROLES.
 */
class ExecutorPlanner
{
    public const HERMES = 'hermes';
    public const OPENCODE = 'opencode';
    public const INTERNAL = 'internal';

    public function forTask(ExecutionRun $run, ExecutionTask $task): string
    {
        if ($task->executor_override === self::HERMES) {
            return self::HERMES;
        }
        if ($task->executor_override === self::OPENCODE) {
            return self::OPENCODE;
        }

        if (! (bool) config('ai.opencode.enabled', true)) {
            return $this->defaultFor();
        }

        $policy = $run->executor_policy ?: 'auto';

        if ($policy === 'hermes') {
            return self::HERMES;
        }

        if ($policy === 'opencode') {
            return self::OPENCODE;
        }

        if ($policy === 'ab') {
            // Selang-seling deterministik: separuh task lewat OpenCode,
            // separuh Hermes, supaya perbandingan jalan tanpa intervensi.
            return $task->id % 2 === 0 ? self::OPENCODE : self::HERMES;
        }

        // auto: peran yang terdaftar memakai OpenCode, sisanya default.
        return in_array($task->assignee, (array) config('ai.opencode.roles', []), true)
            ? self::OPENCODE
            : $this->defaultFor();
    }

    /** Fallback otomatis: task yang gagal di OpenCode dicoba ulang lewat Hermes. */
    public function fallbackTo(ExecutionTask $task, string $executor): void
    {
        $task->forceFill(['executor_override' => $executor])->save();
    }

    private function defaultFor(): string
    {
        return config('ai.execution_driver') === 'hermes' ? self::HERMES : self::INTERNAL;
    }
}
