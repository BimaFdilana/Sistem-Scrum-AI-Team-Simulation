<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoryEstimationAgentRun extends Model
{
    protected $fillable = [
        'story_estimation_run_id',
        'profile_slug',
        'round',
        'status',
        'estimate_points',
        'rationale',
        'error_code',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'estimate_points' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function estimationRun(): BelongsTo
    {
        return $this->belongsTo(StoryEstimationRun::class, 'story_estimation_run_id');
    }
}
