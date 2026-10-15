<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemoServer extends Model
{
    protected $fillable = [
        'project_id', 'port', 'pid', 'status', 'started_at', 'expires_at', 'worktree_path',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'pid' => 'integer',
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isAlive(): bool
    {
        return $this->status === 'RUNNING' && $this->pid
            && function_exists('posix_kill') && @posix_kill($this->pid, 0);
    }
}
