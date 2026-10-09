<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoryEstimationMessage extends Model
{
    protected $fillable = [
        'story_estimation_run_id', 'story_revision', 'round', 'sender_slug',
        'recipient_slug', 'content', 'estimate_points', 'explicit_agree',
    ];

    protected function casts(): array
    {
        return [
            'story_revision' => 'integer',
            'round' => 'integer',
            'estimate_points' => 'integer',
            'explicit_agree' => 'boolean',
        ];
    }

    public function estimationRun(): BelongsTo
    {
        return $this->belongsTo(StoryEstimationRun::class, 'story_estimation_run_id');
    }
}
