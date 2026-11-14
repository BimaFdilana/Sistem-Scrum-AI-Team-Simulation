<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Perintah PO di chat ("Tanya Scrum Master") yang dikerjakan tim lewat alur eksekusi. */
class ChatCommand extends Model
{
    public const UNDERSTANDING = 'UNDERSTANDING';

    public const AWAITING = 'AWAITING_CONFIRMATION';

    public const CONFIRMED = 'CONFIRMED';

    public const CANCELLED = 'CANCELLED';

    public const NOT_UNDERSTOOD = 'NOT_UNDERSTOOD';

    protected $fillable = [
        'user_id', 'chat_conversation_id', 'sprint_id', 'project_id', 'story_id', 'command', 'reported',
        'phase', 'title', 'criteria', 'hint', 'parent_id', 'part_index', 'part_total',
    ];

    protected function casts(): array
    {
        return ['reported' => 'array', 'criteria' => 'array'];
    }

    /** Label singkat fase untuk lencana di chat. */
    public function phaseLabel(): string
    {
        return match ($this->phase) {
            self::UNDERSTANDING => 'Scrum Master memahami perintah',
            self::AWAITING => 'Menunggu konfirmasi Anda',
            self::CANCELLED => 'Dibatalkan',
            self::NOT_UNDERSTOOD => 'Belum bisa dipahami',
            default => 'Dikonfirmasi',
        };
    }

    public function partLabel(): ?string
    {
        return $this->part_total ? 'Bagian '.$this->part_index.' dari '.$this->part_total : null;
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'chat_conversation_id');
    }

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function sprint(): BelongsTo
    {
        return $this->belongsTo(Sprint::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function latestRun(): ?ExecutionRun
    {
        return ExecutionRun::query()->where('story_id', $this->story_id)->latest('id')->first();
    }
}
