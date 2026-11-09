<?php

namespace App\Http\Controllers;

use App\Exceptions\AiUnavailableException;
use App\Exceptions\ModelNotChosenException;
use App\Models\ChatConversation;
use App\Models\Project;
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
    public function index(Request $request, HermesClient $hermes, ChatDisclosurePolicy $disclosurePolicy): View
    {
        $agent = collect(config('agents.profiles'))->firstWhere('slug', 'scrum-master');
        // Dropdown project di halaman chat: tiap project punya riwayat chat
        // sendiri; tanpa project = chat umum (termasuk semua percakapan lama).
        $projects = Project::query()->orderBy('name')->get(['id', 'name']);
        $selectedProject = $request->query('project_id') !== null
            ? $projects->firstWhere('id', (int) $request->query('project_id'))
            : null;
        $conversation = ChatConversation::query()
            ->where('user_id', auth()->id())
            ->where('agent_profile', 'scrum-master')
            ->where('project_id', $selectedProject?->id)
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
            'projects' => $projects,
            'selectedProject' => $selectedProject,
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
        // Chat menunggu AI (termasuk retry pada model yang sama bila
        // upstream gangguan; tidak ada fallback ke model lain).
        // beri batas jujur 5 menit, bukan default PHP 30 detik.
        set_time_limit(300);

        $data = $request->validate([
            'message' => ['required', 'string', 'max:8000'],
            'conversation_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            // Lampiran gambar: JPG/PNG maks 5MB. Disimpan permanen di storage
            // agar tidak hilang setelah dikirim ke AI sebagai prompt vision.
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ]);

        $image = null;
        $imagePath = null;
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            // Simpan permanen: storage/app/public/chat-images/
            $imagePath = $file->store('chat-images', 'public');
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
                'project_id' => $data['project_id'] ?? null,
            ]);

        $userMessage = $conversation->messages()->create([
            'role' => 'user',
            'content' => trim($data['message']).($image !== null ? "\n[lampiran gambar]" : ''),
            'image_path' => $imagePath,
        ]);

        // Konteks project dari percakapan: tanpa project, pertanyaan soal
        // direktori/server hanya bisa bermakna platform (gerbang luas);
        // dengan project, hanya pertanyaan yang menunjuk platform yang ditolak.
        $safeResponse = $disclosurePolicy->safeResponseFor($userMessage->content, null, $conversation->project_id !== null);

        if ($safeResponse !== null) {
            $assistantMessage = $conversation->messages()->create([
                'role' => 'assistant',
                'content' => $safeResponse,
            ]);
            $conversation->touch();

            return $this->messageResponse($conversation, $userMessage, $assistantMessage);
        }

        try {
            // Konteks project hanya ditempel ke pesan yang dikirim ke AI,
            // bukan ke pesan tersimpan — riwayat chat tetap berisi kata-kata
            // pengguna apa adanya.
            $aiMessage = $userMessage->content;
            $project = $conversation->project;
            if ($project !== null) {
                $aiMessage = '[Konteks chat: project ScrumSpace "'.$project->name.'"'
                    .($project->description ? ' — '.$project->description : '').". Jawablah dalam konteks project ini.]\n\n".$aiMessage;
            }
            $content = $hermes->sendMessage($aiMessage, $conversation->uuid, $image);
        } catch (AiUnavailableException $e) {
            // MODEL_PROBLEMATIC: kembalikan pesan penggantian lengkap
            // (nama model + daftar available + perintah ganti di menu),
            // bukan pesan generik.
            return response()->json([
                'conversation_id' => $conversation->uuid,
                'message' => $e->errorCode === 'MODEL_PROBLEMATIC' ? $e->getMessage() : $this->aiErrorMessage($e->errorCode),
            ], $e->errorCode === 'TIMEOUT' ? 504 : 503);
        } catch (ModelNotChosenException $e) {
            return response()->json([
                'conversation_id' => $conversation->uuid,
                'message' => $e->getMessage(),
            ], 503);
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

    /** Pesan kesalahan yang jujur dan spesifik per penyebab, bukan satu kalimat generik. */
    private function aiErrorMessage(string $errorCode): string
    {
        return match ($errorCode) {
            'UPSTREAM_OVERLOADED', 'RATE_LIMITED' => 'Server AI sedang penuh. Tunggu 1–2 menit lalu coba lagi.',
            'TIMEOUT' => 'AI terlalu lama merespons. Coba lagi sebentar.',
            'AUTH_FAILED' => 'Kunci akses AI bermasalah. Periksa kembali koneksi provider di menu AI Providers.',
            'PROVIDER_AUTH_INCOMPLETE' => 'Koneksi akun AI di server belum lengkap (akun Google/Antigravity kehilangan project_id). Putuskan lalu hubungkan ulang provider Google di menu AI Providers, kemudian coba chat lagi.',
            'GATEWAY_UNREACHABLE' => 'Gateway AI tidak dapat dihubungi. Pastikan layanan CLIProxyAPI berjalan.',
            'MODEL_NOT_FOUND' => 'Model AI tidak tersedia. Pilih model lain di konfigurasi agen.',
            'DISABLED' => 'Jalur AI sedang dimatikan oleh admin. Hubungi admin untuk mengaktifkannya.',
            'BAD_REQUEST' => 'Permintaan ke AI tidak valid. Coba ulangi dengan pesan yang berbeda.',
            'EMPTY_RESPONSE' => 'AI mengembalikan respons kosong. Coba lagi sebentar.',
            default => 'AI Agent belum dapat dihubungi. Coba lagi sebentar.',
        };
    }

    private function messageResponse(ChatConversation $conversation, $userMessage, $assistantMessage, array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'conversation_id' => $conversation->uuid,
            'user_message' => [
                'role' => $userMessage->role,
                'content' => $userMessage->content,
                'image_url' => $userMessage->imageUrl(),
            ],
            'assistant_message' => ['role' => $assistantMessage->role, 'content' => $assistantMessage->content],
        ], $extra));
    }
}
