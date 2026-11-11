<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ChatMessage extends Model
{
    protected $fillable = [
        'role',
        'kind',
        'content',
        'image_path',
        'link_url',
        'link_label',
    ];

    public function isReport(): bool
    {
        return $this->kind === 'report';
    }

    /**
     * URL publik gambar lampiran (null bila tidak ada).
     */
    public function imageUrl(): ?string
    {
        if (! $this->image_path) {
            return null;
        }

        return Storage::disk('public')->url($this->image_path);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'chat_conversation_id');
    }
}
