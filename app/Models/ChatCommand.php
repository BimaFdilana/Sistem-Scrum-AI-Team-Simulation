<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Perintah PO di chat ("Tanya Scrum Master") yang dikerjakan tim lewat alur eksekusi. */
class ChatCommand extends Model
{
    protected $fillable = ['user_id', 'chat_conversation_id', 'sprint_id', 'project_id', 'story_id', 'command', 'reported'];

    protected function casts(): array
    {
        return ['reported' => 'array'];
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
