<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExecutionMessage extends Model
{
    protected $fillable = [
        'execution_run_id', 'execution_task_id', 'sender_slug',
        'recipient_slug', 'content', 'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ExecutionRun::class, 'execution_run_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ExecutionTask::class, 'execution_task_id');
    }
}
