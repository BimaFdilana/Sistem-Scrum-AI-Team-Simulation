<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectRelease extends Model
{
    protected $fillable = ['project_id', 'user_id', 'status', 'commit_sha', 'previous_sha', 'backup_tag', 'items', 'commit_count', 'error'];

    protected function casts(): array
    {
        return ['items' => 'array'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
