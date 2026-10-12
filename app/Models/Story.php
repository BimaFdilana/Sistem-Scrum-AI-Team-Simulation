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
        'refined_title',
        'refined_purpose',
        'refined_acceptance_criteria',
        'refined_at',
        'refined_from_run_id',
        'acceptance_status',
        'accepted_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (Story $story): void {
            if ($story->isDirty([
                'title',
                'actor_id',
                'sprint_purpose',
                'acceptance_criteria',
                'project_id',
                'sprint_id',
                'revision',
            ])) {
                $story->estimate_points = null;
            }
            if ($story->isDirty('revision')) {
                // Hasil diskusi milik revisi lama; reset agar estimasi & diskusi baru
                // menghasilkan versi refined yang baru.
                $story->refined_title = null;
                $story->refined_purpose = null;
                $story->refined_acceptance_criteria = null;
                $story->refined_at = null;
                $story->refined_from_run_id = null;
                // Keputusan final ikut ter-reset: revisi baru = pekerjaan baru.
                $story->acceptance_status = null;
                $story->accepted_at = null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'estimate_points' => 'integer',
            'revision' => 'integer',
            'scope_approved_revision' => 'integer',
            'scope_approved_at' => 'datetime',
            'refined_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /** Story terkunci bila PO sudah memberi keputusan final. */
    public function isAccepted(): bool
    {
        return in_array($this->acceptance_status, ['BERHASIL', 'GAGAL'], true);
    }

    public function acceptanceLabel(): ?string
    {
        return match ($this->acceptance_status) {
            'BERHASIL' => 'Berhasil',
            'GAGAL' => 'Gagal',
            default => null,
        };
    }

    /** Versi refined (hasil diskusi SM) menjadi acuan eksekusi bila tersedia. */
    public function hasRefinement(): bool
    {
        return $this->refined_title !== null;
    }

    public function effectiveTitle(): string
    {
        return $this->refined_title ?? $this->title;
    }

    public function effectivePurpose(): ?string
    {
        return $this->refined_purpose ?? $this->sprint_purpose;
    }

    public function effectiveAcceptanceCriteria(): ?string
    {
        return $this->refined_acceptance_criteria ?? $this->acceptance_criteria;
    }

    public function refinedFromRun(): BelongsTo
    {
        return $this->belongsTo(StoryEstimationRun::class, 'refined_from_run_id');
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

    public function executionRuns(): HasMany
    {
        return $this->hasMany(ExecutionRun::class)->latest('id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
