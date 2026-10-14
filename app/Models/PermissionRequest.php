<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PermissionRequest extends Model
{
    /** Permintaan PENDING yang lebih tua dari ini dianggap kedaluwarsa otomatis. */
    public const EXPIRY_HOURS = 24;

    protected $fillable = [
        'execution_run_id', 'title', 'payload', 'status',
        'requested_by', 'resolved_by', 'resolved_at',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'resolved_at' => 'datetime'];
    }

    /**
     * Kedaluwarsakan permintaan PENDING yang tidak diputuskan dalam 24 jam.
     * Dipanggil secara lazy (saat halaman Accepted dibuka / sebelum guard
     * eksekusi) agar tidak butuh cron. Mengembalikan jumlah yang diproses.
     */
    public static function expireStale(): int
    {
        $stale = static::query()
            ->where('status', 'PENDING')
            ->where('created_at', '<', now()->subHours(static::EXPIRY_HOURS))
            ->with('run')
            ->get();

        $count = 0;
        foreach ($stale as $request) {
            $request->forceFill(['status' => 'EXPIRED', 'resolved_at' => now()])->save();
            $run = $request->run;
            if ($run && $run->status === 'AWAITING_PERMISSION') {
                $run->forceFill([
                    'status' => 'FAILED',
                    'error_code' => 'PERMISSION_EXPIRED',
                    'completed_at' => now(),
                ])->save();
                $run->messages()->create([
                    'sender_slug' => 'po',
                    'recipient_slug' => 'scrum-master',
                    'content' => 'Permintaan izin kedaluwarsa otomatis setelah 24 jam tanpa keputusan.',
                ]);
            }
            $count++;
        }

        return $count;
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ExecutionRun::class, 'execution_run_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
