<?php

namespace App\Support\Execution;

use App\Models\ExecutionRun;
use App\Models\ExecutionTask;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Henti paksa eksekusi atas perintah PO.
 *
 * Run langsung ditutup sebagai FAILED dengan kode STOPPED_BY_PO (job yang masih
 * jalan melihat kode ini dan berhenti tanpa menimpa status). Proses agen yang
 * sedang berjalan diminta berhenti lewat penanda cache per folder kerja karena
 * web dan queue worker bisa berjalan sebagai pengguna OS berbeda.
 */
class ExecutionStopper
{
    public const CODE = 'STOPPED_BY_PO';

    /** Task yang sudah beres tidak ikut dibatalkan. */
    private const KEEP = ['APPROVED', 'MERGED', 'DONE', 'COMPLETED', 'FAILED', 'CANCELLED'];

    /** @return array{stopped: bool, note: string} */
    public function stop(ExecutionRun $run): array
    {
        $running = ExecutionTask::query()->where('execution_run_id', $run->id)->where('status', 'IN_PROGRESS')->count();

        $closed = $run->transitionTo('FAILED', [...DirectWorkspace::ACTIVE, ExecutionRun::NEEDS_PO], [
            'error_code' => self::CODE,
            'completed_at' => now(),
            'summary' => 'Dihentikan paksa oleh PO pada '.now()->format('d M Y H:i').'.',
        ]);
        if (! $closed) {
            return ['stopped' => false, 'note' => 'Eksekusi #'.$run->id.' sudah tidak berjalan.'];
        }

        ExecutionTask::query()->where('execution_run_id', $run->id)->whereNotIn('status', self::KEEP)
            ->update(['status' => 'CANCELLED', 'last_error' => self::CODE]);

        $project = $run->project();
        if ($project?->workspace_path) {
            // Penanda dibaca pelari agen (OpenCode) tiap setengah detik; berlaku 10 menit.
            Cache::put(self::flagKey((string) $project->workspace_path), $run->id, now()->addMinutes(10));
        }

        // Tidak ada task yang sedang dikerjakan: baseline bisa dikembalikan sekarang.
        // Bila ada, job-nya sendiri yang mengembalikan setelah proses agen berhenti.
        $note = $running === 0 ? $this->release($run) : null;

        return ['stopped' => true, 'note' => $note ?? ''];
    }

    /** Kembalikan baseline ke cabang basis (pekerjaan setengah jadi disimpan di cabang run). */
    public function release(ExecutionRun $run): ?string
    {
        if (($run->mode ?: config('execution.mode', 'worktree')) !== 'direct' || ($project = $run->project()) === null) {
            return null;
        }
        try {
            return app(DirectWorkspace::class)->releaseAfterFailure($project, $run);
        } catch (Throwable) {
            return null; // eksekusi berikutnya tetap memulihkan lewat recoverLeftovers()
        }
    }

    public static function wasStopped(?ExecutionRun $run): bool
    {
        return $run !== null && ExecutionRun::query()->whereKey($run->id)
            ->where('status', 'FAILED')->where('error_code', self::CODE)->exists();
    }

    public static function flagKey(string $workspacePath): string
    {
        return 'exec-stop:'.md5((string) (realpath($workspacePath) ?: $workspacePath));
    }

    /** Dipakai pelari agen: apakah folder kerja ini diminta berhenti? */
    public static function stopRequested(string $cwd): bool
    {
        return Cache::has(self::flagKey($cwd));
    }

    /** Penanda dihapus saat run baru dimulai agar tidak menghentikan pekerjaan berikutnya. */
    public static function clearFlag(string $workspacePath): void
    {
        Cache::forget(self::flagKey($workspacePath));
    }
}
