<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ExecutionRun extends Model
{
    protected $fillable = [
        'story_id', 'requested_by', 'status', 'summary', 'error_code', 'completed_at',
    ];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
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
}
