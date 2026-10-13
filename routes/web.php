<?php

use App\Http\Controllers\ActorController;
use App\Http\Controllers\AgentConfigurationController;
use App\Http\Controllers\AiProviderController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\ExecutionController;
use App\Http\Controllers\PermissionRequestController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectDownloadController;
use App\Http\Controllers\RepairOrderController;
use App\Http\Controllers\SprintController;
use App\Http\Controllers\StoryController;
use App\Http\Controllers\StoryEstimationController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'));

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/dashboard', [WorkspaceController::class, 'dashboard'])->name('dashboard');
    Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
    Route::post('/chat/messages', [ChatController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('chat.messages.store');
    Route::get('/agents', [WorkspaceController::class, 'agents'])->name('agents.index');
    Route::get('/ai-providers', [AiProviderController::class, 'index'])->name('ai-providers.index');
    Route::get('/ai-providers/{provider}/connect', [AiProviderController::class, 'connect'])->name('ai-providers.connect');
    Route::post('/ai-providers/{provider}/callback', [AiProviderController::class, 'callback'])->middleware('throttle:20,1')->name('ai-providers.callback');
    Route::post('/ai-providers/{provider}/disconnect', [AiProviderController::class, 'disconnect'])->name('ai-providers.disconnect');
    Route::get('/agents/{slug}/config', [AgentConfigurationController::class, 'edit'])->name('agents.config.edit');
    Route::put('/agents/{slug}/config', [AgentConfigurationController::class, 'update'])->name('agents.config.update');
    Route::get('/agents/{slug}/config/history', [AgentConfigurationController::class, 'history'])->name('agents.config.history');
    Route::post('/agents/{slug}/config/restore/{version}', [AgentConfigurationController::class, 'restore'])->whereNumber('version')->name('agents.config.restore');
    Route::get('/agents/{slug}/config/connectivity', [AgentConfigurationController::class, 'connectivity'])->middleware('throttle:20,1')->name('agents.config.connectivity');
    Route::get('/agents/{slug}/activity', [WorkspaceController::class, 'agentActivity'])->name('agents.activity');
    Route::get('/agents/{slug}/chat', [ChatController::class, 'profileChat'])->name('agents.chat');
    Route::post('/agents/{slug}/chat/messages', [ChatController::class, 'storeForProfile'])
        ->middleware('throttle:30,1')
        ->name('agents.chat.messages.store');
    Route::resource('actors', ActorController::class)->except(['show']);
    Route::resource('projects', ProjectController::class)->only(['index', 'create', 'store', 'show']);
    Route::get('/projects/{project}/stories', [StoryController::class, 'index'])->name('projects.stories.index');
    Route::get('/projects/{project}/sprint-backlog', [StoryController::class, 'index'])->name('projects.sprint-backlog.index');
    Route::put('/projects/{project}/backlog', [StoryController::class, 'updateBacklog'])->name('projects.backlog.update');
    Route::get('/sprints/{sprint}/stories/create', [StoryController::class, 'createForSprint'])->name('sprints.stories.create');
    Route::post('/sprints/{sprint}/stories', [StoryController::class, 'storeForSprint'])->name('sprints.stories.store');
    Route::post('/stories/{story}/estimation-runs', [StoryEstimationController::class, 'store'])->middleware('throttle:6,1')->name('stories.estimations.store');
    Route::post('/stories/{story}/estimation-runs/{run}/retry', [StoryEstimationController::class, 'retry'])->middleware('throttle:6,1')->name('stories.estimations.retry');
    Route::post('/stories/{story}/estimation-runs/{run}/agents/{agentRun}/retry', [StoryEstimationController::class, 'retryAgent'])->middleware('throttle:6,1')->name('stories.estimations.agents.retry');
    Route::get('/stories/{story}/discussion', [StoryEstimationController::class, 'discussion'])->name('stories.discussion.show');
    Route::get('/stories/{story}/estimation-runs/{run}/status', [StoryEstimationController::class, 'status'])->name('stories.estimations.status');
    Route::post('/stories/{story}/estimation-runs/{run}/report', [StoryEstimationController::class, 'report'])->name('stories.estimations.report');
    Route::post('/stories/{story}/estimation-runs/{run}/cancel', [StoryEstimationController::class, 'cancel'])->name('stories.estimations.cancel');
    Route::post('/stories/{story}/executions', [ExecutionController::class, 'store'])->middleware('throttle:6,1')->name('stories.executions.store');
    Route::get('/stories/{story}/executions/{run}', [ExecutionController::class, 'show'])->name('stories.executions.show');
    Route::post('/stories/{story}/executions/{run}/accept', [ExecutionController::class, 'accept'])->name('stories.executions.accept');
    Route::get('/repair-orders', [RepairOrderController::class, 'index'])->name('repair-orders.index');
    Route::get('/repair-orders/{repairOrder}', [RepairOrderController::class, 'show'])->name('repair-orders.show');
    Route::post('/repair-orders/{repairOrder}/confirm', [RepairOrderController::class, 'confirm'])->middleware('throttle:20,1')->name('repair-orders.confirm');
    Route::post('/repair-orders/{repairOrder}/cancel', [RepairOrderController::class, 'cancel'])->middleware('throttle:20,1')->name('repair-orders.cancel');
    Route::post('/repair-orders/{repairOrder}/run', [RepairOrderController::class, 'run'])->middleware('throttle:6,1')->name('repair-orders.run');
    Route::get('/repair-orders/{repairOrder}/executions/{run}', [RepairOrderController::class, 'showExecution'])->name('repair-orders.executions.show');
    Route::post('/repair-orders/{repairOrder}/executions/{run}/accept', [RepairOrderController::class, 'accept'])->name('repair-orders.executions.accept');
    Route::get('/accepted', [PermissionRequestController::class, 'index'])->name('accepted.index');
    Route::post('/accepted/{permissionRequest}/approve', [PermissionRequestController::class, 'approve'])->name('accepted.approve');
    Route::post('/accepted/{permissionRequest}/reject', [PermissionRequestController::class, 'reject'])->name('accepted.reject');
    Route::post('/projects/{project}/demo/start', [DemoController::class, 'start'])->middleware('throttle:6,1')->name('projects.demo.start');
    Route::post('/projects/{project}/demo/stop', [DemoController::class, 'stop'])->name('projects.demo.stop');
    Route::get('/projects/{project}/download', [ProjectDownloadController::class, 'download'])->name('projects.download');
    Route::get('/stories/{story}/tasks', [TaskController::class, 'index'])->name('stories.tasks.index');
    Route::get('/stories/{story}/tasks/create', [TaskController::class, 'create'])->name('stories.tasks.create');
    Route::post('/stories/{story}/tasks', [TaskController::class, 'store'])->name('stories.tasks.store');
    Route::get('/stories/{story}/edit', [StoryController::class, 'edit'])->name('stories.edit');
    Route::patch('/stories/{story}', [StoryController::class, 'update'])->name('stories.update');
    Route::get('/projects/{project}/sprints', [SprintController::class, 'index'])->name('projects.sprints.index');
    Route::get('/projects/{project}/sprints/create', [SprintController::class, 'create'])->name('projects.sprints.create');
    Route::post('/projects/{project}/sprints', [SprintController::class, 'store'])->name('projects.sprints.store');
    Route::get('/sprints/{sprint}', [SprintController::class, 'show'])->name('sprints.show');
    Route::put('/sprints/{sprint}/scope', [SprintController::class, 'updateScope'])->name('sprints.scope.update');
    Route::post('/sprints/{sprint}/approve-scope', [SprintController::class, 'approveScope'])->name('sprints.scope.approve');
    Route::post('/sprints/{sprint}/start', [SprintController::class, 'start'])->name('sprints.start');
    Route::post('/sprints/{sprint}/generate-goal', [SprintController::class, 'generateGoal'])->name('sprints.goal.generate');
});
