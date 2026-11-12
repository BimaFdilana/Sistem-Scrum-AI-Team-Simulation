<?php

namespace App\Http\Controllers;

use App\Jobs\BreakdownExecutionJob;
use App\Models\ChatCommand;
use App\Models\ExecutionRun;
use App\Models\Sprint;
use App\Models\Story;
use App\Support\Chat\SprintChat;
use App\Support\Execution\DirectWorkspace;
use App\Support\Workspace\BaselineSnapshot;
use Illuminate\Http\JsonResponse;
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
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'min:5', 'max:4000'],
            'sprint_id' => ['required', 'integer', 'exists:sprints,id'],
        ]);
        $sprint = Sprint::query()->with('project')->findOrFail($data['sprint_id']);
        $project = $sprint->project;

        if (! $project?->workspace_path || ! is_dir($project->workspace_path)) {
            return response()->json(['message' => 'Kode project belum siap. Tunggu proses clone/scaffold selesai.'], 422);
        }
        $busy = ExecutionRun::query()->with('story')->whereIn('status', DirectWorkspace::ACTIVE)
            ->whereHas('story', fn ($q) => $q->where('project_id', $project->id))->first();
        if ($busy !== null) {
            return response()->json(['message' => 'Tim masih mengerjakan '.$busy->subjectLabel().' di project ini. Kirim perintah lagi setelah selesai agar pekerjaan tidak bertabrakan.'], 409);
        }

        $command = trim($data['message']);
        $conversation = SprintChat::conversation($request->user(), $sprint);

        [$story, $run] = DB::transaction(function () use ($command, $project, $sprint, $conversation, $request) {
            $story = Story::query()->create([
                'project_id' => $project->id,
                'origin' => 'CHAT',
                'sprint_id' => null,
                'title' => Str::limit(Str::of($command)->explode("\n")->first(), 150, '…'),
                'description' => $command,
                'sprint_purpose' => $command,
                'acceptance_criteria' => $command,
            ]);
            ChatCommand::query()->create([
                'user_id' => $request->user()->id,
                'chat_conversation_id' => $conversation->id,
                'sprint_id' => $sprint->id,
                'project_id' => $project->id,
                'story_id' => $story->id,
                'command' => $command,
            ]);
            $run = ExecutionRun::query()->create([
                'story_id' => $story->id,
                'requested_by' => $request->user()->id,
                'status' => 'QUEUED',
                'mode' => config('execution.mode', 'worktree'),
                'qa_mode' => config('execution.qa_mode', 'per_task'),
            ]);
            $conversation->messages()->create([
                'role' => 'user',
                'kind' => 'command',
                'content' => $command,
                'execution_run_id' => $run->id,
            ]);
            $conversation->touch();

            return [$story, $run];
        });

        if (($run->mode ?: config('execution.mode', 'worktree')) === 'direct') {
            try {
                app(BaselineSnapshot::class)->backup($project, 'run-'.$run->id);
            } catch (Throwable $exception) {
                Log::warning('Snapshot baseline gagal', ['run' => $run->id, 'error' => $exception->getMessage()]);
            }
        }
        BreakdownExecutionJob::dispatch($run->id);

        return response()->json([
            'conversation_id' => $conversation->uuid,
            'story_id' => $story->id,
            'run_id' => $run->id,
            'status_url' => route('chat.commands.status', $run, false),
        ], 202);
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
