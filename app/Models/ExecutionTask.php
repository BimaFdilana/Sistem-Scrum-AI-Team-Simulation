<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExecutionTask extends Model
{
    protected $fillable = [
        'execution_run_id', 'title', 'description', 'assignee', 'status',
        'worktree_path', 'branch', 'revision_count', 'qa_feedback',
    ];

    protected function casts(): array
    {
        return ['revision_count' => 'integer'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ExecutionRun::class, 'execution_run_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ExecutionMessage::class);
    }

    public function assigneeName(): string
    {
        return match ($this->assignee) {
            'frontend-developer' => 'Frontend Developer',
            'backend-developer' => 'Backend Developer',
            default => $this->assignee,
        };
    }
}
