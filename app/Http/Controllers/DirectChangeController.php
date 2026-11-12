<?php

namespace App\Http\Controllers;

use App\Jobs\DirectChangeJob;
use App\Models\DirectChange;
use App\Models\ExecutionRun;
use App\Models\Sprint;
use App\Support\Chat\SprintChat;
use App\Support\Execution\DirectWorkspace;
use App\Support\Workspace\WorktreeManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * PO meminta perubahan kode langsung dari chat, di luar alur Scrum.
 * Dikerjakan peran Developer (mode langsung), bukan Scrum Master.
 */
class DirectChangeController extends Controller
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
        $busyChange = DirectChange::query()->where('project_id', $project->id)->whereIn('status', DirectChange::ACTIVE)->exists();
        if ($busyChange) {
            return response()->json(['message' => 'Masih ada perubahan kode langsung yang sedang dikerjakan di project ini. Tunggu sampai selesai.'], 409);
        }
        $busyRun = ExecutionRun::query()->whereIn('status', DirectWorkspace::ACTIVE)
            ->whereHas('story', fn ($q) => $q->where('project_id', $project->id))->first();
        if ($busyRun) {
            return response()->json(['message' => 'Eksekusi '.$busyRun->subjectLabel().' sedang berjalan di project ini. Tunggu sampai selesai agar perubahan tidak bertabrakan.'], 409);
        }

        $conversation = SprintChat::conversation($request->user(), $sprint);
        $change = DirectChange::query()->create([
            'project_id' => $project->id,
            'sprint_id' => $sprint->id,
            'user_id' => $request->user()->id,
            'chat_conversation_id' => $conversation->id,
            'request' => trim($data['message']),
            'status' => 'QUEUED',
        ]);
        $userMessage = $conversation->messages()->create([
            'role' => 'user',
            'kind' => 'change',
            'content' => trim($data['message']),
            'direct_change_id' => $change->id,
        ]);
        $conversation->messages()->create([
            'role' => 'assistant',
            'kind' => 'change',
            'content' => 'Permintaan perubahan kode diterima.',
            'direct_change_id' => $change->id,
        ]);
        $conversation->touch();

        DirectChangeJob::dispatch($change->id);

        return response()->json([
            'conversation_id' => $conversation->uuid,
            'change' => $this->present($change),
            'user_message' => ['content' => $userMessage->content],
        ], 202);
    }

    public function status(Request $request, DirectChange $change): JsonResponse
    {
        abort_unless((int) $change->user_id === (int) $request->user()->id, 403);

        return response()->json($this->present($change));
    }

    public function revert(Request $request, DirectChange $change, WorktreeManager $git): JsonResponse
    {
        abort_unless((int) $change->user_id === (int) $request->user()->id, 403);
        if (! $change->canRevert()) {
            return response()->json(['message' => 'Perubahan ini tidak bisa dibatalkan (belum selesai atau sudah dibatalkan).'], 422);
        }
        $path = (string) $change->project?->workspace_path;
        try {
            $current = $git->currentBranch($path);
            if ($current !== $change->base_branch || $git->dirtyFiles($path) !== []) {
                throw new RuntimeException('Kode project sedang dipakai proses lain. Coba lagi setelah eksekusi selesai.');
            }
            $git->revertCommit($path, $change->commit_after);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => 'Gagal membatalkan: '.Str::limit($exception->getMessage(), 300)], 409);
        }
        $change->forceFill(['status' => 'REVERTED', 'reverted_at' => now()])->save();
        $change->conversation?->messages()->create([
            'role' => 'assistant',
            'kind' => 'report',
            'content' => 'Perubahan kode #'.$change->id.' dibatalkan. Kode kembali seperti sebelum permintaan: "'.Str::limit($change->request, 120).'".',
        ]);

        return response()->json($this->present($change->fresh()));
    }

    /** @return array<string, mixed> */
    private function present(DirectChange $change): array
    {
        return [
            'id' => $change->id,
            'status' => $change->status,
            'label' => $change->statusLabel(),
            'active' => $change->isActive(),
            'files' => $change->files ?? [],
            'summary' => $change->summary,
            'error' => $change->error,
            'can_revert' => $change->canRevert(),
            'status_url' => route('chat.changes.status', $change, false),
            'revert_url' => route('chat.changes.revert', $change, false),
        ];
    }
}
