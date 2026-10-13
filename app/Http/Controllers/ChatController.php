<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Services\ChatDisclosurePolicy;
use App\Services\HermesClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

class ChatController extends Controller
{
    public function index(HermesClient $hermes, ChatDisclosurePolicy $disclosurePolicy): View
    {
        $agent = collect(config('agents.profiles'))->firstWhere('slug', 'scrum-master');
        $conversation = ChatConversation::query()
            ->where('user_id', auth()->id())
            ->where('agent_profile', 'scrum-master')
            ->latest('updated_at')
            ->first();

        $messages = $conversation?->messages()->oldest('id')->get() ?? collect();
        $messages->each(function ($message) use ($disclosurePolicy): void {
            if ($message->role === 'assistant' && $disclosurePolicy->containsSensitiveDisclosure($message->content)) {
                $message->content = ChatDisclosurePolicy::SAFE_RESPONSE;
            }
        });

        return view('workspace.chat', [
            'agent' => $agent,
            'conversation' => $conversation,
            'messages' => $messages,
            'chatAvailable' => $hermes->isAvailable(),
            'chatAction' => route('chat.messages.store', [], false),
            'activePage' => 'chat',
        ]);
    }

    public function profileChat(string $slug): RedirectResponse
    {
        $this->findProfile($slug);

        return redirect()->route('chat.index');
    }

    public function store(Request $request, HermesClient $hermes, ChatDisclosurePolicy $disclosurePolicy): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:8000'],
            'conversation_id' => ['nullable', 'uuid'],
            // Lampiran gambar: JPG/PNG maks 5MB, diteruskan ke AI lalu dibuang.
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ]);

        $image = null;
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $image = [
                'data' => base64_encode($file->get()),
                'mime' => $file->getMimeType(),
            ];
        }

        $user = $request->user();
        $conversation = isset($data['conversation_id'])
            ? ChatConversation::query()
                ->where('user_id', $user->id)
                ->where('agent_profile', 'scrum-master')
                ->where('uuid', $data['conversation_id'])
                ->firstOrFail()
            : ChatConversation::query()->create([
                'user_id' => $user->id,
                'uuid' => (string) Str::uuid(),
                'title' => Str::limit(trim($data['message']), 160, ''),
                'agent_profile' => 'scrum-master',
            ]);

        $userMessage = $conversation->messages()->create([
            'role' => 'user',
            // Gambar tidak disimpan; hanya penanda bahwa pesan disertai gambar.
            'content' => trim($data['message']).($image !== null ? "\n[lampiran gambar]" : ''),
        ]);

        $safeResponse = $disclosurePolicy->safeResponseFor($userMessage->content);

        if ($safeResponse !== null) {
            $assistantMessage = $conversation->messages()->create([
                'role' => 'assistant',
                'content' => $safeResponse,
            ]);
            $conversation->touch();

            return $this->messageResponse($conversation, $userMessage, $assistantMessage);
        }

        try {
            $content = $hermes->sendMessage($userMessage->content, $conversation->uuid, $image);
        } catch (RuntimeException) {
            return response()->json([
                'conversation_id' => $conversation->uuid,
                'message' => 'AI Agent belum dapat dihubungi. Coba lagi sebentar.',
            ], 503);
        }

        $assistantMessage = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $content,
        ]);
        $conversation->touch();

        return $this->messageResponse($conversation, $userMessage, $assistantMessage);
    }

    public function storeForProfile(Request $request, string $slug): JsonResponse
    {
        $this->findProfile($slug);

        abort(403, 'Hubungi Scrum Master untuk meminta bantuan agent spesialis.');
    }

    private function findProfile(string $slug): array
    {
        $agent = collect(config('agents.profiles'))->firstWhere('slug', $slug);

        abort_if(! $agent || $slug === 'scrum-master', 404);

        return $agent;
    }

    private function messageResponse(ChatConversation $conversation, $userMessage, $assistantMessage): JsonResponse
    {
        return response()->json([
            'conversation_id' => $conversation->uuid,
            'user_message' => ['role' => $userMessage->role, 'content' => $userMessage->content],
            'assistant_message' => ['role' => $assistantMessage->role, 'content' => $assistantMessage->content],
        ]);
    }
}
