<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoryEstimationRun extends Model
{
    protected $fillable = [
        'story_id',
        'requested_by',
        'story_revision',
        'status',
        'summary',
        'approved_points',
        'approved_by',
        'approved_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'story_revision' => 'integer',
            'approved_points' => 'integer',
            'approved_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function agentRuns(): HasMany
    {
        return $this->hasMany(StoryEstimationAgentRun::class)->orderBy('round')->orderBy('profile_slug');
    }
}
