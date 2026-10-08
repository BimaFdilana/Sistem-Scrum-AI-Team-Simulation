<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sprint extends Model
{
    use HasFactory;

    protected $fillable = [
        'backlog_id',
        'project_id',
        'name',
        'goal',
        'goal_source',
        'start_date',
        'end_date',
        'status',
        'revision',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'revision' => 'integer',
            'scope_approved_revision' => 'integer',
            'scope_approved_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function backlog(): BelongsTo
    {
        return $this->belongsTo(Backlog::class);
    }

    public function stories(): HasMany
    {
        return $this->hasMany(Story::class);
    }
}
