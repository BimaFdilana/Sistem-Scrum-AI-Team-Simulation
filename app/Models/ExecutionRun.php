<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ExecutionRun extends Model
{
    protected $fillable = [
        'story_id', 'repair_order_id', 'requested_by', 'status', 'summary', 'error_code', 'completed_at',
    ];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function repairOrder(): BelongsTo
    {
        return $this->belongsTo(RepairOrder::class);
    }

    /** Project target eksekusi: dari story atau dari perintah perbaikan. */
    public function project(): ?Project
    {
        return $this->story?->project ?? $this->repairOrder?->project;
    }

    /** Label subjek untuk judul/permintaan izin ("US-3 — ..." atau "Perintah #2 — ..."). */
    public function subjectLabel(): string
    {
        if ($this->story) {
            return $this->story->display_id.' — '.$this->story->effectiveTitle();
        }
        if ($this->repairOrder) {
            return 'Perintah #'.$this->repairOrder->id.' — '.$this->repairOrder->title;
        }

        return 'Eksekusi #'.$this->id;
    }

    public function subjectTitle(): string
    {
        return $this->story?->effectiveTitle()
            ?? $this->repairOrder?->title
            ?? 'Eksekusi #'.$this->id;
    }

    public function subjectCriteria(): string
    {
        return $this->story?->effectiveAcceptanceCriteria()
            ?? $this->repairOrder?->summary
            ?? '-';
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ExecutionTask::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ExecutionMessage::class);
    }

    public function permissionRequest(): HasOne
    {
        return $this->hasOne(PermissionRequest::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [
            'QUEUED', 'BREAKDOWN', 'AWAITING_PERMISSION', 'EXECUTING', 'REVIEWING', 'MERGING',
        ], true);
    }

    public function isTerminal(): bool
    {
        // FAILED ikut terminal: run yang gagal di tengah jalan (AI timeout,
        // izin ditolak, workspace hilang) harus bisa ditutup PO dengan
        // keputusan final, bukan menggantung selamanya.
        return in_array($this->status, ['COMPLETED', 'BLOCKED', 'FAILED'], true);
    }

    protected static function booted(): void
    {
        // Sinkronkan status perintah perbaikan saat execution run-nya terminal.
        static::updated(function (ExecutionRun $run): void {
            if (! $run->wasChanged('status') || ! $run->repair_order_id) {
                return;
            }
            $orderStatus = [
                'COMPLETED' => 'COMPLETED',
                'BLOCKED' => 'BLOCKED',
                'FAILED' => 'FAILED',
            ][$run->status] ?? null;
            if ($orderStatus !== null) {
                $run->repairOrder?->forceFill(['status' => $orderStatus])->save();
            }
        });
    }
}
