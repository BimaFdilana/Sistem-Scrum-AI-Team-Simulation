<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    protected $fillable = [
        'role',
        'content',
        'image_path',
    ];

    /**
     * URL publik gambar lampiran (null bila tidak ada).
     */
    public function imageUrl(): ?string
    {
        if (! $this->image_path) {
            return null;
        }

        return \Illuminate\Support\Facades\Storage::disk('public')->url($this->image_path);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'chat_conversation_id');
    }
}
