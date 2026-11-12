<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DirectChange extends Model
{
    public const ACTIVE = ['QUEUED', 'RUNNING'];

    protected $fillable = [
        'project_id', 'sprint_id', 'user_id', 'chat_conversation_id', 'request', 'status',
        'base_branch', 'branch', 'commit_before', 'commit_after', 'files', 'summary', 'error',
        'started_at', 'completed_at', 'reverted_at',
    ];

    protected function casts(): array
    {
        return [
            'files' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'reverted_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function sprint(): BelongsTo
    {
        return $this->belongsTo(Sprint::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'chat_conversation_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function canRevert(): bool
    {
        return $this->status === 'DONE' && $this->commit_after !== null && $this->reverted_at === null;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'QUEUED' => 'Menunggu giliran',
            'RUNNING' => 'Developer sedang mengubah kode',
            'DONE' => 'Selesai diterapkan',
            'NO_CHANGES' => 'Tidak ada file yang berubah',
            'FAILED' => 'Gagal',
            'REVERTED' => 'Dibatalkan',
            default => $this->status,
        };
    }
}
