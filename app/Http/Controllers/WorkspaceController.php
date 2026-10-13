<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\StoryEstimationAgentRun;
use App\Services\AiStatusService;
use App\Services\HermesClient;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function dashboard(HermesClient $hermes): View
    {
        return view('workspace.dashboard', [
            'agents' => config('agents.profiles'),
            'hermesConnected' => $hermes->isAvailable(),
            'activePage' => 'dashboard',
        ]);
    }

    public function agentActivity(string $slug): View
    {
        $agent = collect(config('agents.profiles'))->firstWhere('slug', $slug);
        abort_unless($agent, 404);

        $tasks = Task::query()
            ->with(['story.project'])
            ->where('assignee_profile', $agent['profile'])
            ->latest('created_at')
            ->get();
        $estimationRuns = Schema::hasTable('story_estimation_agent_runs')
            ? StoryEstimationAgentRun::query()
                ->with(['estimationRun.story.project'])
                ->where('profile_slug', $agent['slug'])
                ->latest('created_at')
                ->get()
            : collect();

        return view('workspace.agent-activity', [
            'agent' => $agent,
            'tasks' => $tasks,
            'estimationRuns' => $estimationRuns,
            'activePage' => 'agent-activity',
        ]);
    }

    public function agents(AiStatusService $status): View
    {
        return view('workspace.agents', [
            'agents' => config('agents.profiles'),
            'agentStatus' => $status->agents(),
            'activePage' => 'agents',
        ]);
    }
}
