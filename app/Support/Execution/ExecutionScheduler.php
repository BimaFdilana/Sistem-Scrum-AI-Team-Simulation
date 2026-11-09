<?php

namespace App\Support\Execution;

use App\Jobs\ExecuteExecutionTaskJob;
use App\Models\ExecutionRun;
use App\Models\ExecutionTask;
use App\Models\PermissionRequest;

/**
 * Penentu task mana yang boleh dikirim ke worker sekarang.
 *
 * Model lama: satu kartu pada satu waktu, selalu. Akibatnya run 5 task butuh
 * berjam-jam walau task-task itu menyentuh file yang berbeda.
 *
 * Model sekarang:
 *  - task hanya siap bila semua dependensi eksplisitnya (depends_on) APPROVED;
 *  - task yang file-nya beririsan dengan task lain yang sedang berjalan atau
 *    lebih dulu menunggu TIDAK boleh dikirim bersamaan (menghindari konflik
 *    merge dan worker saling menimpa);
 *  - maksimal `execution.max_parallel` task berjalan bersamaan.
 */
class ExecutionScheduler
{
    /** @return array<int, ExecutionTask> */
    public function readyTasks(ExecutionRun $run): array
    {
        $tasks = $run->tasks()->orderBy('sort_order')->orderBy('id')->get();
        $scope = new TaskFileScope;

        $busyFiles = [];
        foreach ($tasks as $task) {
            if (in_array($task->status, ['IN_PROGRESS', 'REVISION', 'IN_REVIEW'], true)) {
                $busyFiles = array_merge($busyFiles, $scope->filesFor($run, $task));
            }
        }
        $busyFiles = array_values(array_unique($busyFiles));

        $ready = [];
        $claimedFiles = $busyFiles;
        $unknownDispatched = false;

        foreach ($tasks as $task) {
            if ($task->status !== 'PENDING') {
                continue;
            }

            if (! $this->dependenciesApproved($run, $task)) {
                continue;
            }

            $files = $scope->filesFor($run, $task);

            if ($files === []) {
                // Cakupan file tidak diketahui: jangan pernah paralel, karena
                // benturan tidak bisa dibuktikan. Satu task saja per putaran.
                if ($unknownDispatched) {
                    continue;
                }

                $unknownDispatched = true;
                $ready[] = $task;

                continue;
            }

            $overlap = array_values(array_intersect($files, $claimedFiles));

            if ($overlap !== []) {
                // Ada task lain (aktif atau lebih awal) yang menyentuh file
                // yang sama: tunggu, jangan paralel.
                continue;
            }

            $ready[] = $task;
            $claimedFiles = array_values(array_unique(array_merge($claimedFiles, $files)));
        }

        return $ready;
    }

    /**
     * Kirim task siap ke worker, dibatasi kuota paralel.
     *
     * @return array<int, int> id task yang dikirim
     */
    public function dispatchReady(ExecutionRun $run, ?int $limit = null): array
    {
        $limit ??= $this->maxParallel($run);
        if ($limit <= 0) {
            return [];
        }

        $active = $run->tasks()
            ->whereIn('status', ['IN_PROGRESS', 'IN_REVIEW'])
            ->count();

        $slots = max(0, $limit - $active);
        if ($slots === 0) {
            return [];
        }

        $dispatched = [];
        foreach (array_slice($this->readyTasks($run), 0, $slots) as $task) {
            ExecuteExecutionTaskJob::dispatch($run->id, $task->id);
            $dispatched[] = $task->id;
        }

        return $dispatched;
    }

    public function maxParallel(?ExecutionRun $run = null): int
    {
        // Mode langsung mengedit baseline: dua agent pada folder yang sama
        // akan saling menimpa, jadi selalu satu task pada satu waktu.
        if ($run !== null && ($run->mode ?: config('execution.mode', 'worktree')) === 'direct') {
            return 1;
        }

        return max(1, (int) config('execution.max_parallel', 2));
    }

    /** Dependensi eksplisit: semua task yang dirujuk harus APPROVED. */
    private function dependenciesApproved(ExecutionRun $run, ExecutionTask $task): bool
    {
        $dependencies = $task->depends_on ?? [];
        if (! is_array($dependencies) || $dependencies === []) {
            return true;
        }

        $ids = array_map('intval', $dependencies);
        $approved = $run->tasks()
            ->whereIn('id', $ids)
            ->where('status', 'APPROVED')
            ->count();

        return $approved === count(array_unique($ids));
    }
}
