<?php

namespace App\Services;

use App\Exceptions\AiUnavailableException;
use App\Exceptions\ModelNotChosenException;
use App\Exceptions\ModelProblemException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Satu pintu klasifikasi kegagalan AI untuk semua job eksekusi.
 *
 * Pelajaran eksekusi US-01 #8 (16:10 UTC): tiga job review gagal
 * RATE_LIMITED hampir bersamaan, dan catch di handle() langsung
 * menandai seluruh run FAILED — padahal rate limit itu sementara
 * dan task lain sehat. Sejak perbaikan ini, error sementara TIDAK
 * pernah menggagalkan run: job dilepas kembali ke antrean dengan
 * jeda (release), dan baru dinyatakan permanen bila percobaan
 * antrean sudah habis. Error permanen tetap gagal cepat.
 */
class AiGateway
{
    /** Kode error AI yang sifatnya sementara (boleh antre ulang). */
    public const TRANSIENT_CODES = [
        'RATE_LIMITED',
        'UPSTREAM_OVERLOADED',
        'TIMEOUT',
        'GATEWAY_UNREACHABLE',
        'EMPTY_RESPONSE',
    ];

    /** Jumlah maksimum percobaan antrean untuk job eksekusi/review. */
    public const MAX_QUEUE_ATTEMPTS = 5;

    public static function isTransient(string $errorCode): bool
    {
        return in_array($errorCode, self::TRANSIENT_CODES, true);
    }

    /**
     * Kegagalan pemilihan/pemakaian model yang harus sampai ke PO
     * sebagai peringatan (bukan antre ulang, bukan substitusi diam-diam):
     * role belum memilih, atau model yang dipilih ternyata bermasalah
     * (MODEL_PROBLEMATIC — sengaja bukan kode transient).
     */
    public static function isModelChoiceFailure(Throwable $exception): bool
    {
        return $exception instanceof ModelNotChosenException
            || $exception instanceof ModelProblemException
            || ($exception instanceof AiUnavailableException && $exception->errorCode === 'MODEL_PROBLEMATIC');
    }

    /**
     * Jeda antre ulang (detik) untuk percobaan ke-$attempt (1-based).
     * Retry-After provider dihormati sebagai batas bawah; jeda
     * dibatasi 600 detik agar job tidak tertunda tanpa batas.
     */
    public static function releaseDelay(int $attempt, ?int $retryAfter = null): int
    {
        $base = 60 * (2 ** max(0, $attempt - 1));

        return (int) min(max($base, $retryAfter ?? 0), 600);
    }

    /**
     * Catat kegagalan AI dengan ID eksekusi/task — menutup celah
     * pencatatan #8, di mana entri ERROR rate limit dari Laravel
     * tidak membawa ID sehingga tidak bisa dipetakan ke task.
     */
    public static function logFailure(int $runId, int $taskId, string $job, AiUnavailableException $exception, int $attempt, bool $willRetry): void
    {
        Log::warning('Execution AI failure', [
            'execution_run_id' => $runId,
            'execution_task_id' => $taskId,
            'job' => $job,
            'error_code' => $exception->errorCode,
            'retry_after' => $exception->retryAfter,
            'attempt' => $attempt,
            'will_retry' => $willRetry,
        ]);
    }
}
