<?php

namespace App\Support\Execution;

use App\Models\ExecutionRun;
use App\Models\ExecutionTask;
use App\Models\PermissionRequest;

/**
 * Sumber tunggal daftar file yang menjadi tanggung jawab sebuah task.
 *
 * Dipakai scheduler (menentukan benturan file) dan prompt worker, supaya
 * keduanya tidak pernah berbeda pendapat soal cakupan task.
 */
class TaskFileScope
{
    /**
     * @return array<int, string>
     */
    public function filesFor(ExecutionRun $run, ExecutionTask $task): array
    {
        $requests = PermissionRequest::query()
            ->where('execution_run_id', $run->id)
            ->orderBy('id', 'desc')
            ->get();

        foreach ($requests as $request) {
            $payloadTasks = $request->payload['tasks'] ?? null;
            if (! is_array($payloadTasks) || $payloadTasks === []) {
                continue;
            }

            $candidate = $payloadTasks[$task->sort_order] ?? null;
            if (! is_array($candidate) || ($candidate['title'] ?? null) !== $task->title) {
                $candidate = null;
                foreach ($payloadTasks as $payloadTask) {
                    if (is_array($payloadTask)
                        && ($payloadTask['title'] ?? null) === $task->title
                        && ($payloadTask['assignee'] ?? null) === $task->assignee) {
                        $candidate = $payloadTask;
                        break;
                    }
                }
            }

            if (is_array($candidate) && is_array($candidate['files'] ?? null)) {
                $clean = $this->clean($candidate['files']);
                if ($clean !== []) {
                    return $clean;
                }
            }
        }

        if (preg_match('/FILE\s*:\s*(.+?)(?=\n\s*(HASIL|SELESAI BILA|KERJAKAN)\b|$)/is', (string) $task->description, $matches)) {
            $parts = preg_split('/[\n,]+/', $matches[1]) ?: [];

            return $this->clean(array_map(fn ($part) => trim($part, " \t\r\n-•`\"'"), $parts));
        }

        return [];
    }

    /**
     * @param  array<int, mixed>  $files
     * @return array<int, string>
     */
    private function clean(array $files): array
    {
        $clean = [];

        foreach ($files as $file) {
            if (! is_string($file)) {
                continue;
            }

            $file = trim($file, " \t\r\n`\"'");
            if ($file === '' || str_starts_with($file, '/') || str_contains($file, '..')) {
                continue;
            }

            $clean[] = $file;
        }

        return array_values(array_unique($clean));
    }
}
