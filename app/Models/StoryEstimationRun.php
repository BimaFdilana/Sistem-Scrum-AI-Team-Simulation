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
        'story_snapshot_hash',
        'status',
        'summary',
        'consensus_points',
        'report_sent_at',
        'approved_points',
        'approved_by',
        'approved_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'story_revision' => 'integer',
            'consensus_points' => 'integer',
            'report_sent_at' => 'datetime',
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

    public function messages(): HasMany
    {
        return $this->hasMany(StoryEstimationMessage::class)->orderBy('round')->orderBy('id');
    }
}
