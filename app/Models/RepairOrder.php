<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RepairOrder extends Model
{
    protected $fillable = [
        'requested_by', 'project_id', 'title', 'summary', 'original_message',
        'scope_risk', 'risk_note', 'status', 'target_branch', 'acceptance_status', 'accepted_at',
        'conversation_uuid',
    ];

    protected function casts(): array
    {
        return [
            'scope_risk' => 'boolean',
            'accepted_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ExecutionRun::class);
    }

    public function latestRun(): ?ExecutionRun
    {
        return $this->runs()->latest('id')->first();
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['CONFIRMED', 'RUNNING'], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['COMPLETED', 'FAILED', 'BLOCKED', 'CANCELLED'], true);
    }

    public function isAccepted(): bool
    {
        return $this->acceptance_status !== null;
    }

    public function acceptanceLabel(): string
    {
        return $this->acceptance_status === 'BERHASIL' ? 'Berhasil'
            : ($this->acceptance_status === 'GAGAL' ? 'Gagal' : '-');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'DRAFT' => 'Menunggu konfirmasi',
            'CONFIRMED' => 'Siap dijalankan',
            'RUNNING' => 'Dikerjakan tim AI',
            'COMPLETED' => 'Selesai',
            'FAILED' => 'Gagal',
            'BLOCKED' => 'Diblokir',
            'CANCELLED' => 'Dibatalkan',
            default => $this->status,
        };
    }
}
