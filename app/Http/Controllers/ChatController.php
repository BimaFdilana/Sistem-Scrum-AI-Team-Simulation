<?php

namespace App\Http\Controllers;

use App\Exceptions\AiUnavailableException;
use App\Exceptions\ModelNotChosenException;
use App\Models\ChatConversation;
use App\Models\ExecutionRun;
use App\Models\Project;
use App\Models\Sprint;
use App\Services\ChatDisclosurePolicy;
use App\Services\HermesClient;
use App\Support\Chat\SprintChat;
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
        $user = $request->user();

        // Daftar percakapan: project → sprint. Tiap sprint punya satu thread
        // sendiri; obrolan dan laporan SM tidak dicampur lintas sprint.
        $projects = Project::query()->with(['sprints' => fn ($q) => $q->orderByDesc('id')])->orderBy('name')->get(['id', 'name']);
        $sprints = $projects->flatMap(fn (Project $project) => $project->sprints);
        $threads = ChatConversation::query()->where('user_id', $user->id)
            ->where('agent_profile', 'scrum-master')->whereNotNull('sprint_id')
            ->withCount(['messages as report_count' => fn ($q) => $q->where('kind', 'report')])
            ->get()->keyBy('sprint_id');

        // Arsip: percakapan lama yang belum terikat sprint (hanya baca).
        $archive = ChatConversation::query()->where('user_id', $user->id)
            ->where('agent_profile', 'scrum-master')->whereNull('sprint_id')
            ->whereHas('messages')->latest('updated_at')->limit(15)->get(['id', 'uuid', 'title', 'project_id', 'updated_at']);
        $archived = $request->query('arsip') !== null
            ? $archive->firstWhere('uuid', (string) $request->query('arsip'))
            : null;

        $selectedSprint = null;
        if ($archived === null) {
            $selectedSprint = $request->query('sprint') !== null
                ? $sprints->firstWhere('id', (int) $request->query('sprint'))
                : null;
            if ($selectedSprint === null && $request->query('project_id') !== null) {
                $forProject = $projects->firstWhere('id', (int) $request->query('project_id'))?->sprints;
                $selectedSprint = $forProject?->firstWhere('status', 'ACTIVE') ?? $forProject?->first();
            }
            if ($selectedSprint === null && $request->query('sprint') === null) {
                $recent = $threads->sortByDesc('updated_at')->first();
                $selectedSprint = ($recent ? $sprints->firstWhere('id', $recent->sprint_id) : null)
                    ?? $sprints->firstWhere('status', 'ACTIVE')
                    ?? $sprints->sortByDesc('id')->first();
            }
        }

        $conversation = $archived ?? ($selectedSprint ? $threads->get($selectedSprint->id) : null);
        $messages = $conversation?->messages()->with(['executionRun.story', 'chatCommand.story'])->oldest('id')->get() ?? collect();
        // Tombol keputusan hanya pada laporan terbaru tiap run, dan hanya untuk run terbaru tiap perintah.
        $latestReportIds = $messages->where('kind', 'report')->whereNotNull('execution_run_id')
            ->groupBy('execution_run_id')->map(fn ($group) => $group->max('id'))->all();
        $commandStoryIds = $messages->pluck('executionRun.story_id')
            ->merge($messages->pluck('chatCommand.story_id'))->filter()->unique()->values();
        $latestRunIds = $commandStoryIds->isEmpty() ? [] : ExecutionRun::query()
            ->whereIn('story_id', $commandStoryIds)->selectRaw('story_id, max(id) as id')->groupBy('story_id')
            ->pluck('id', 'story_id')->all();
        $messages->each(function ($message) use ($disclosurePolicy): void {
            if ($message->role === 'assistant' && $disclosurePolicy->containsSensitiveDisclosure($message->content)) {
                $message->content = ChatDisclosurePolicy::SAFE_RESPONSE;
            }
        });

        $sprintProject = $selectedSprint ? $projects->firstWhere('id', $selectedSprint->project_id) : null;
        $stories = $selectedSprint ? $selectedSprint->stories()->get() : collect();

        return view('workspace.chat', [
            'agent' => $agent,
            'conversation' => $conversation,
            'messages' => $messages,
            'chatAvailable' => $hermes->isAvailable(),
            'chatAction' => route('chat.messages.store', [], false),
            'projects' => $projects,
            'threads' => $threads,
            'archive' => $archive,
            'archived' => $archived,
            'selectedSprint' => $selectedSprint,
            'selectedProject' => $sprintProject,
            'sprintStories' => $stories,
            // Dua transkrip terpisah: perintah ke tim (Tanya) dan sisanya (Laporan).
            'commandMessages' => $messages->filter(fn ($m) => $m->kind === 'command' || $m->kind === 'command_plan' || $m->chat_command_id !== null || ($m->execution_run_id && $m->executionRun?->story?->isChatCommand()))->values(),
            'reportMessages' => $messages->reject(fn ($m) => $m->kind === 'command' || $m->kind === 'command_plan' || $m->chat_command_id !== null || ($m->execution_run_id && $m->executionRun?->story?->isChatCommand()))->values(),
            'latestReportIds' => $latestReportIds,
            'latestPlanIds' => $messages->where('kind', 'command_plan')->whereNotNull('chat_command_id')
                ->groupBy('chat_command_id')->map(fn ($group) => $group->max('id'))->all(),
            'latestRunIds' => $latestRunIds,
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
            'sprint_id' => ['nullable', 'integer', 'exists:sprints,id'],
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
        $sprint = isset($data['sprint_id']) ? Sprint::query()->find($data['sprint_id']) : null;
        if (isset($data['conversation_id'])) {
            $conversation = ChatConversation::query()
                ->where('user_id', $user->id)
                ->where('agent_profile', 'scrum-master')
                ->where('uuid', $data['conversation_id'])
                ->firstOrFail();
            // Arsip lama tidak menerima pesan baru; sprint-nya harus dipilih.
            abort_if($conversation->sprint_id === null && $sprint !== null, 422, 'Percakapan ini arsip lama; pilih sprint untuk melanjutkan.');
            $sprint = $conversation->sprint_id !== null ? $conversation->sprint()->first() : null;
        } elseif ($sprint !== null) {
            $conversation = SprintChat::conversation($user, $sprint);
        } else {
            $conversation = ChatConversation::query()->create([
                'user_id' => $user->id,
                'uuid' => (string) Str::uuid(),
                'title' => Str::limit(trim($data['message']), 160, ''),
                'agent_profile' => 'scrum-master',
                'project_id' => $data['project_id'] ?? null,
            ]);
        }

        $userMessage = $conversation->messages()->create([
            'role' => 'user',
            'content' => trim($data['message']).($image !== null ? "\n[lampiran gambar]" : ''),
            'image_path' => $imagePath,
        ]);

        // Konteks project dari percakapan: tanpa project, pertanyaan soal
        // direktori/server hanya bisa bermakna platform (gerbang luas);
        // dengan project, hanya pertanyaan yang menunjuk platform yang ditolak.
        $safeResponse = $disclosurePolicy->safeResponseFor($userMessage->content, null, $conversation->project_id !== null || $sprint !== null);

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
            $aiMessage = $this->aiMessage($conversation, $sprint, $userMessage);
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

    /**
     * Pesan ke SM: konteks sprint + riwayat singkat + pertanyaan PO. Konteks
     * hanya ditempel ke pesan yang dikirim ke AI, bukan ke pesan tersimpan.
     */
    private function aiMessage(ChatConversation $conversation, ?Sprint $sprint, $userMessage): string
    {
        $parts = [];
        $project = $sprint?->project ?? $conversation->project;
        if ($project !== null) {
            $parts[] = '[Konteks chat: project ScrumSpace "'.$project->name.'"'
                .($project->description ? ' — '.$project->description : '').'. Jawablah dalam konteks project ini.]';
        }
        if ($sprint !== null) {
            $stories = $sprint->stories()->get()->map(fn ($story) => '- '.$story->display_id.' '.$story->effectiveTitle()
                .' · '.($story->estimate_points !== null ? $story->estimate_points.' poin' : 'belum diestimasi')
                .' · '.($story->acceptance_status ?? 'belum diterima'))->take(25)->implode("\n");
            $parts[] = '[Sprint: "'.$sprint->name.'" (status '.$sprint->status.')'
                .($sprint->goal ? ', goal: '.$sprint->goal : '')."\nStory di sprint ini:\n".($stories !== '' ? $stories : '(belum ada story)').']';
        }
        $history = $conversation->messages()->where('id', '<', $userMessage->id)
            ->latest('id')->limit(8)->get()->reverse()
            ->map(fn ($message) => ($message->role === 'user' ? 'PO' : 'SM').': '.Str::limit(preg_replace('/\s+/', ' ', (string) $message->content), 600))
            ->implode("\n");
        if ($history !== '') {
            $parts[] = "[Riwayat percakapan sprint ini, dari yang lama ke baru]\n".$history;
        }

        if ($parts === []) {
            return $userMessage->content;
        }

        return implode("\n\n", $parts)."\n\nPesan PO saat ini: ".$userMessage->content;
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
            'QUOTA_EXHAUSTED' => 'Kuota model AI habis. Ganti model aktif di menu AI Providers, atau tunggu kuota pulih (biasanya beberapa jam).',
            'TIMEOUT' => 'AI terlalu lama merespons. Coba lagi sebentar.',
            'AUTH_FAILED' => 'Kunci akses AI bermasalah. Periksa kembali koneksi provider di menu AI Providers.',
            'PROVIDER_AUTH_INCOMPLETE' => 'Koneksi akun AI di server belum lengkap (akun Google/Antigravity kehilangan project_id). Putuskan lalu hubungkan ulang provider Google di menu AI Providers, kemudian coba chat lagi.',
            'GATEWAY_UNREACHABLE' => 'Gateway AI tidak dapat dihubungi. Pastikan layanan gateway AI di server berjalan.',
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
