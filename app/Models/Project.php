<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Schema;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'project_type',
        'stack',
        'status',
    ];

    protected static function booted(): void
    {
        static::created(function (Project $project): void {
            if (! Schema::hasTable('backlogs')) {
                return;
            }

            $project->backlog()->create([
                'name' => $project->name.' Backlog',
                'description' => null,
            ]);
        });
    }

    public function backlog(): HasOne
    {
        return $this->hasOne(Backlog::class);
    }

    public function sourceArchives(): HasMany
    {
        return $this->hasMany(SourceArchive::class);
    }

    public function stories(): HasMany
    {
        return $this->hasMany(Story::class);
    }

    public function sprints(): HasMany
    {
        return $this->hasMany(Sprint::class);
    }
}
