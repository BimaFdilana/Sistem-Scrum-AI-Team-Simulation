<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Models\Project;
use App\Models\RepairOrder;
use App\Services\ChatDisclosurePolicy;
use App\Services\HermesClient;
use App\Services\RepairOrderDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

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
        // Chat menunggu AI (termasuk retry + fallback bila upstream gangguan);
        // beri batas jujur 5 menit, bukan default PHP 30 detik.
        set_time_limit(300);

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

        // Deteksi perintah perbaikan SEBELUM chat biasa: SM mengklasifikasikan
        // pesan PO, menampilkan ringkasan + tombol konfirmasi. Tidak ada yang
        // dieksekusi otomatis dari chat.
        $repair = $this->maybeDetectRepairOrder($hermes, $conversation, $userMessage);
        if ($repair !== null) {
            return $this->messageResponse($conversation, $userMessage, $repair['assistant_message'], [
                'repair_order' => $repair['card'],
            ]);
        }

        try {
            $content = $hermes->sendMessage($userMessage->content, $conversation->uuid, $image);
        } catch (\App\Exceptions\AiUnavailableException $e) {
            return response()->json([
                'conversation_id' => $conversation->uuid,
                'message' => $this->aiErrorMessage($e->errorCode),
            ], $e->errorCode === 'TIMEOUT' ? 504 : 503);
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
            'user_message' => ['role' => $userMessage->role, 'content' => $userMessage->content],
            'assistant_message' => ['role' => $assistantMessage->role, 'content' => $assistantMessage->content],
        ], $extra));
    }

    /**
     * @return array{assistant_message: \App\Models\ChatMessage, card: array}|null
     */
    private function maybeDetectRepairOrder(HermesClient $hermes, ChatConversation $conversation, $userMessage): ?array
    {
        $detector = app(RepairOrderDetector::class);
        $text = (string) $userMessage->content;

        if (! $detector->looksLikeCommand($text)) {
            return null;
        }
        $projects = Project::query()->orderBy('name')->get();
        if ($projects->isEmpty()) {
            return null;
        }

        try {
            $result = $detector->classify($hermes, $text, $projects->pluck('name')->all());
        } catch (Throwable) {
            return null; // fail-safe: lanjut sebagai chat biasa
        }
        if (! $result['is_repair_order']) {
            return null;
        }

        $project = $detector->resolveProject($result['project_name'], $projects);
        if ($project === null && $projects->count() === 1) {
            $project = $projects->first();
        }
        $needsProject = $project === null; // >1 project & tak disebut -> SM tanya dulu

        $order = RepairOrder::query()->create([
            'requested_by' => $conversation->user_id,
            'project_id' => $project?->id,
            'title' => $result['title'] !== '' ? $result['title'] : Str::limit(trim($text), 80, ''),
            'summary' => $result['summary'] !== '' ? $result['summary'] : trim($text),
            'original_message' => trim($text),
            'scope_risk' => $result['risky'],
            'risk_note' => $result['risk_note'],
            'status' => 'DRAFT',
            'conversation_uuid' => $conversation->uuid,
        ]);

        $lines = [
            'Saya mendeteksi ini sebagai PERINTAH PERBAIKAN (bukan pertanyaan biasa).',
            '',
            'Ringkasan: '.$order->title,
            $order->summary,
        ];
        if ($order->scope_risk) {
            $lines[] = '';
            $lines[] = 'Peringatan: perintah ini terdeteksi berisiko — '.($order->risk_note ?? 'mohon periksa cakupannya.').' Saya hanya akan menjalankan bagian yang aman.';
        }
        if ($needsProject) {
            $lines[] = '';
            $lines[] = 'Project mana yang dimaksud? Pilih di bawah, lalu tekan Konfirmasi.';
        } else {
            $lines[] = '';
            $lines[] = 'Project: '.$project->name.'. Tekan Konfirmasi untuk menyimpan perintah ini ke menu Perintah Perbaikan, atau Batalkan bila saya salah mengenali.';
        }

        $assistantMessage = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => implode("\n", $lines),
        ]);
        $conversation->touch();

        return [
            'assistant_message' => $assistantMessage,
            'card' => [
                'id' => $order->id,
                'title' => $order->title,
                'summary' => $order->summary,
                'project_id' => $project?->id,
                'project_name' => $project?->name,
                'needs_project' => $needsProject,
                'projects' => $projects->map(fn (Project $p) => ['id' => $p->id, 'name' => $p->name])->all(),
                'risky' => $order->scope_risk,
                'risk_note' => $order->risk_note,
            ],
        ];
    }
}
