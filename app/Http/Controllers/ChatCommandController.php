<?php

namespace App\Http\Controllers;

use App\Jobs\BreakdownExecutionJob;
use App\Jobs\UnderstandChatCommandJob;
use App\Models\ChatCommand;
use App\Models\ExecutionRun;
use App\Models\Sprint;
use App\Models\Story;
use App\Support\Chat\ChatCommandReporter;
use App\Support\Chat\SprintChat;
use App\Support\Execution\DirectWorkspace;
use App\Support\Workspace\BaselineSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Tanya Scrum Master": PO memberi perintah di chat, Scrum Master memecahnya
 * menjadi tugas Backend/Frontend, QA memeriksa, lalu SM melapor ke chat.
 * Tanpa langkah backlog (story, estimasi, persetujuan sprint).
 */
class ChatCommandController extends Controller
{
    /**
     * Perintah masuk: Scrum Master memahaminya dulu (judul + kriteria), lalu PO
     * mengonfirmasi. Tim belum bekerja sebelum PO menekan "Sesuai, kerjakan".
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'min:5', 'max:6000'],
            'sprint_id' => ['required', 'integer', 'exists:sprints,id'],
        ]);
        $sprint = Sprint::query()->with('project')->findOrFail($data['sprint_id']);
        $project = $sprint->project;

        if (! $project?->workspace_path || ! is_dir($project->workspace_path)) {
            return response()->json(['message' => 'Kode project belum siap. Tunggu proses clone/scaffold selesai.'], 422);
        }

        $text = trim($data['message']);
        $conversation = SprintChat::conversation($request->user(), $sprint);
        $command = DB::transaction(function () use ($text, $project, $sprint, $conversation, $request) {
            $command = ChatCommand::query()->create([
                'user_id' => $request->user()->id,
                'chat_conversation_id' => $conversation->id,
                'sprint_id' => $sprint->id,
                'project_id' => $project->id,
                'command' => $text,
                'phase' => ChatCommand::UNDERSTANDING,
            ]);
            $conversation->messages()->create([
                'role' => 'user',
                'kind' => 'command',
                'content' => $text,
                'chat_command_id' => $command->id,
            ]);
            $conversation->touch();

            return $command;
        });

        UnderstandChatCommandJob::dispatch($command->id);

        return response()->json([
            'conversation_id' => $conversation->uuid,
            'command_id' => $command->id,
            'phase_url' => route('chat.commands.phase', $command, false),
        ], 202);
    }

    /** PO mengonfirmasi (dan boleh mengoreksi) rumusan SM; tim mulai bekerja. */
    public function confirm(Request $request, ChatCommand $command): RedirectResponse
    {
        $this->authorizeOwner($request, $command);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'criteria' => ['required', 'string', 'max:4000'],
        ]);
        $criteria = array_values(array_filter(array_map(
            fn ($line) => trim(preg_replace('/^\s*(?:[-*•]|\d+[.)])\s*/u', '', $line) ?? ''),
            preg_split('/\R+/u', $data['criteria']) ?: []
        ), fn ($line) => $line !== ''));
        if ($criteria === [] || count($criteria) > 10) {
            return back()->withErrors(['command' => 'Tulis 1 sampai 10 kriteria, satu kriteria per baris.']);
        }
        if ($command->phase !== ChatCommand::AWAITING) {
            return back()->withErrors(['command' => 'Rencana ini sudah diproses atau dibatalkan.']);
        }
        $project = $command->project;
        if (! $project?->workspace_path || ! is_dir($project->workspace_path)) {
            return back()->withErrors(['command' => 'Kode project belum siap.']);
        }
        $busy = ExecutionRun::query()->with('story')->whereIn('status', DirectWorkspace::ACTIVE)
            ->whereHas('story', fn ($q) => $q->where('project_id', $project->id))->first();
        if ($busy !== null) {
            return back()->withErrors(['command' => 'Tim masih mengerjakan '.$busy->subjectLabel().'. Klik "Sesuai, kerjakan" lagi setelah selesai agar pekerjaan tidak bertabrakan.']);
        }

        $run = DB::transaction(function () use ($command, $data, $criteria, $request) {
            $fields = [
                'title' => trim($data['title']),
                'description' => $command->command,
                'sprint_purpose' => $command->command,
                // Satu baris = satu kriteria yang sudah dikonfirmasi PO.
                'acceptance_criteria' => implode("\n", $criteria),
            ];
            $story = $command->story_id ? Story::query()->find($command->story_id) : null;
            if ($story === null || $story->isLockedFromExecution()) {
                $story = Story::query()->create($fields + ['project_id' => $command->project_id, 'origin' => 'CHAT', 'sprint_id' => null]);
            } else {
                $story->forceFill($fields + ['acceptance_status' => null, 'accepted_at' => null, 'accepted_execution_run_id' => null])->save();
            }
            $command->forceFill([
                'phase' => ChatCommand::CONFIRMED, 'story_id' => $story->id,
                'title' => $fields['title'], 'criteria' => $criteria,
            ])->save();

            $run = ExecutionRun::query()->create([
                'story_id' => $story->id,
                'requested_by' => $request->user()->id,
                'status' => 'QUEUED',
                'mode' => config('execution.mode', 'worktree'),
                'qa_mode' => config('execution.qa_mode', 'per_task'),
            ]);
            $command->conversation?->messages()->create([
                'role' => 'assistant', 'kind' => 'report',
                'content' => 'Dikonfirmasi. Tim mulai mengerjakan "'.$fields['title'].'" dengan '.count($criteria).' kriteria.',
                'chat_command_id' => $command->id,
                'execution_run_id' => $run->id,
            ]);
            $command->conversation?->touch();

            return $run;
        });

        if (($run->mode ?: config('execution.mode', 'worktree')) === 'direct') {
            try {
                app(BaselineSnapshot::class)->backup($project, 'run-'.$run->id);
            } catch (Throwable $exception) {
                Log::warning('Snapshot baseline gagal', ['run' => $run->id, 'error' => $exception->getMessage()]);
            }
        }
        BreakdownExecutionJob::dispatch($run->id);

        return back()->with('status', 'Perintah dikonfirmasi. Tim mulai bekerja.');
    }

    public function cancel(Request $request, ChatCommand $command): RedirectResponse
    {
        $this->authorizeOwner($request, $command);
        if (in_array($command->phase, [ChatCommand::AWAITING, ChatCommand::NOT_UNDERSTOOD], true)) {
            $command->forceFill(['phase' => ChatCommand::CANCELLED])->save();
        }

        return back()->with('status', 'Rencana perintah dibatalkan.');
    }

    /**
     * Rumuskan ulang: untuk kegagalan yang PASTI terulang (perintah tidak jelas /
     * terlalu besar), SM merumuskan ulang dengan mengetahui penyebab gagalnya,
     * bukan menjalankan hal yang sama lagi.
     */
    public function rethink(Request $request, ChatCommand $command): RedirectResponse
    {
        $this->authorizeOwner($request, $command);
        $run = $command->latestRun();
        if ($run !== null && in_array($run->status, DirectWorkspace::ACTIVE, true)) {
            return back()->withErrors(['command' => 'Perintah ini masih dikerjakan tim.']);
        }
        if ($command->story?->isLockedFromExecution()) {
            return back()->withErrors(['command' => 'Perintah ini sudah diterima. Kirim perintah baru untuk perubahan lanjutan.']);
        }

        $hint = $run !== null && in_array($run->status, ['FAILED', 'BLOCKED'], true)
            ? ChatCommandReporter::failureExplanation($run).' Detail: '.Str::limit((string) $run->summary, 500)
            : 'Rumusan sebelumnya belum bisa dipakai; buat lebih jelas dan lebih kecil.';
        $command->forceFill(['phase' => ChatCommand::UNDERSTANDING, 'hint' => $hint])->save();
        $command->conversation?->messages()->create([
            'role' => 'assistant', 'kind' => 'report',
            'content' => 'Saya rumuskan ulang perintah ini dengan memperhitungkan penyebab gagal sebelumnya.',
            'chat_command_id' => $command->id,
        ]);
        UnderstandChatCommandJob::dispatch($command->id);

        return back()->with('status', 'Scrum Master merumuskan ulang perintah.');
    }

    /** Fase perintah (dipoll selama SM memahami perintah). */
    public function phase(Request $request, ChatCommand $command): JsonResponse
    {
        $this->authorizeOwner($request, $command, true);

        return response()->json(['phase' => $command->phase, 'label' => $command->phaseLabel(), 'active' => $command->phase === ChatCommand::UNDERSTANDING]);
    }

    private function authorizeOwner(Request $request, ChatCommand $command, bool $asNotFound = false): void
    {
        if ((int) $command->user_id !== (int) $request->user()->id) {
            abort($asNotFound ? 404 : 403);
        }
    }

    /** Status singkat perintah untuk kartu di chat (dipoll). */
    public function status(Request $request, ExecutionRun $run): JsonResponse
    {
        $command = ChatCommand::query()->where('story_id', $run->story_id)->first();
        abort_unless($command !== null && (int) $command->user_id === (int) $request->user()->id, 404);

        return response()->json([
            'status' => $run->status,
            'label' => self::label($run->status),
            'active' => in_array($run->status, DirectWorkspace::ACTIVE, true),
        ]);
    }

    public static function label(string $status): string
    {
        return match ($status) {
            'QUEUED' => 'Menunggu giliran',
            'BREAKDOWN' => 'Scrum Master membagi tugas',
            'AWAITING_PERMISSION', 'EXECUTING' => 'Tim sedang mengerjakan',
            'REVIEWING', 'QA_REVIEW' => 'QA memeriksa hasil',
            'MERGING' => 'Merapikan hasil',
            'COMPLETED' => 'Selesai, menunggu keputusan Anda',
            'NEEDS_PO' => 'Berhenti, butuh keputusan Anda',
            'FAILED', 'BLOCKED' => 'Gagal',
            default => $status,
        };
    }
}
