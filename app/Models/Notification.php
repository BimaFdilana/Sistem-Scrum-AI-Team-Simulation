<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    public const TYPE_PATCH_FAILED = 'patch_failed';

    protected $fillable = [
        'user_id', 'type', 'title', 'message', 'link', 'read_at',
    ];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function markAsRead(): void
    {
        if (! $this->isRead()) {
            $this->forceFill(['read_at' => now()])->save();
        }
    }

    /**
     * Buat notifikasi patch gagal untuk PO (requested_by dari execution run).
     */
    public static function patchFailed(ExecutionRun $run, ExecutionTask $task, string $reason): ?self
    {
        $userId = $run->requested_by;
        if (! $userId) {
            return null;
        }

        $storyLabel = $run->story?->title ?? ('Story #'.$run->story_id);

        return self::query()->create([
            'user_id' => $userId,
            'type' => self::TYPE_PATCH_FAILED,
            'title' => 'Patch task ditolak',
            'message' => "Patch task #{$task->id} ({$task->title}, {$storyLabel}) ditolak: {$reason} Eksekusi dihentikan.",
            'link' => route('stories.executions.show', [$run->story_id, $run->id]),
        ]);
    }
}
