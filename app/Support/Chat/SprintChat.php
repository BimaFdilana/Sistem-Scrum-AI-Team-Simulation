<?php

namespace App\Support\Chat;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Sprint;
use App\Models\Story;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Chat Scrum Master dipisah per sprint: satu percakapan per (pengguna, sprint).
 * Obrolan PO↔SM dan laporan otomatis SM (hasil estimasi, ringkasan eksekusi)
 * masuk ke percakapan sprint yang sama, tidak lagi dicampur lintas sprint.
 */
class SprintChat
{
    public const AGENT = 'scrum-master';

    /** Percakapan sprint milik pengguna; dibuat bila belum ada. */
    public static function conversation(User|int $user, Sprint $sprint): ChatConversation
    {
        $userId = $user instanceof User ? $user->id : $user;

        return ChatConversation::query()->firstOrCreate(
            ['user_id' => $userId, 'agent_profile' => self::AGENT, 'sprint_id' => $sprint->id],
            [
                'uuid' => (string) Str::uuid(),
                'title' => $sprint->name,
                'project_id' => $sprint->project_id,
            ],
        );
    }

    /**
     * Percakapan tempat laporan story ditaruh: percakapan sprint story itu, atau
     * (story tanpa sprint) chat umum lama agar laporan tidak hilang.
     */
    public static function forStory(User|int $user, ?Story $story, string $fallbackTitle): ChatConversation
    {
        $sprint = $story?->sprint_id === null ? null : $story->sprint()->first();
        if ($sprint !== null) {
            return self::conversation($user, $sprint);
        }

        $userId = $user instanceof User ? $user->id : $user;

        return ChatConversation::query()->where('user_id', $userId)
            ->where('agent_profile', self::AGENT)->whereNull('sprint_id')->whereNull('project_id')
            ->latest('updated_at')->first()
            ?? ChatConversation::query()->create([
                'user_id' => $userId,
                'uuid' => (string) Str::uuid(),
                'title' => $fallbackTitle,
                'agent_profile' => self::AGENT,
            ]);
    }

    /** Tambahkan laporan otomatis SM (kartu laporan, bukan gelembung obrolan). */
    public static function report(ChatConversation $conversation, string $content, ?string $linkUrl = null, ?string $linkLabel = null): ChatMessage
    {
        $message = $conversation->messages()->create([
            'role' => 'assistant',
            'kind' => 'report',
            'content' => $content,
            'link_url' => $linkUrl,
            'link_label' => $linkLabel,
        ]);
        $conversation->touch();

        return $message;
    }

    /** URL halaman chat untuk sprint tertentu (relatif, aman di belakang proxy). */
    public static function url(?Sprint $sprint, array $query = []): string
    {
        return route('chat.index', array_filter(['sprint' => $sprint?->id] + $query), false);
    }
}
