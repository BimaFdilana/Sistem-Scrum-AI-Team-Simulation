<?php

use App\Http\Controllers\ActorController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ProjectController;
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
    Route::post('/stories/{story}/estimation-runs', [StoryEstimationController::class, 'store'])->name('stories.estimations.store');
    Route::post('/stories/{story}/estimation-runs/{run}/approve', [StoryEstimationController::class, 'approve'])->name('stories.estimations.approve');
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
