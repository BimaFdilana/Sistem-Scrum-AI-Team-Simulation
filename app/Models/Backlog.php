<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Backlog extends Model
{
    protected $fillable = ['project_id', 'name', 'description'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function sprints(): HasMany
    {
        return $this->hasMany(Sprint::class);
    }
}
