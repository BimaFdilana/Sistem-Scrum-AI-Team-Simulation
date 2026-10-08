<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Story extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'sprint_id',
        'title',
        'description',
        'acceptance_criteria',
        'actor_id',
        'sprint_purpose',
        'sprint_story_status',
        'user_story_key',
        'estimate_points',
        'scope_status',
        'revision',
    ];

    protected function casts(): array
    {
        return [
            'estimate_points' => 'integer',
            'revision' => 'integer',
            'scope_approved_revision' => 'integer',
            'scope_approved_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Actor::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function getDisplayIdAttribute(): string
    {
        return $this->user_story_key ?? 'US-'.str_pad((string) $this->getKey(), 2, '0', STR_PAD_LEFT);
    }

    public function scopeApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scope_approved_by');
    }

    public function sprint(): BelongsTo
    {
        return $this->belongsTo(Sprint::class);
    }

    public function estimationRuns(): HasMany
    {
        return $this->hasMany(StoryEstimationRun::class)->latest('id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
