<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu temuan QA yang bisa dibalas developer dan ditengahi SM.
 */
class ExecutionFinding extends Model
{
    public const OPEN = 'OPEN';

    public const DISMISSED = 'DISMISSED';

    public const RESOLVED = 'RESOLVED';

    protected $fillable = [
        'execution_run_id', 'execution_task_id', 'assignee', 'severity', 'file', 'line', 'issue', 'verify',
        'status', 'round', 'dev_stance', 'dev_reason', 'sm_winner', 'sm_reason',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ExecutionRun::class, 'execution_run_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ExecutionTask::class, 'execution_task_id');
    }
}
