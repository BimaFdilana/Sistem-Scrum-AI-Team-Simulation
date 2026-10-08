<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'assignee_profile',
        'status',
        'story_revision',
        'revision',
        'created_by',
        'blocker_reason',
        'started_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'story_revision' => 'integer',
            'revision' => 'integer',
            'started_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
