<?php

use App\Http\Controllers\Api\BookBlockGroupController;
use App\Http\Controllers\Api\BookController;
use App\Http\Controllers\Api\BookPageController;
use App\Http\Controllers\Api\BookPageEditingController;
use App\Http\Controllers\Api\BookSpaceController;
use App\Http\Controllers\Api\ContractorController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EntityLinkController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\EventSectionController;
use App\Http\Controllers\Api\EventTypeController;
use App\Http\Controllers\Api\FactController;
use App\Http\Controllers\Api\KpiController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ScopeController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\TaskBlockerController;
use App\Http\Controllers\Api\TaskChecklistItemController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TaskConversationController;
use App\Http\Controllers\Api\TaskPlannerController;
use App\Http\Controllers\Api\TaskRelationController;
use App\Http\Controllers\Auth\SessionController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('api/auth')->middleware('spa.request')->group(function (): void {
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:login');

    Route::middleware(['auth', 'active'])->group(function (): void {
        Route::get('/me', [SessionController::class, 'show']);
        Route::post('/logout', [SessionController::class, 'destroy']);
    });
});

Route::prefix('api')->middleware(['spa.request', 'auth', 'active'])->group(function (): void {
    Route::get('/scopes', [ScopeController::class, 'index']);
    Route::post('/scopes', [ScopeController::class, 'store']);

    Route::prefix('/scopes/{scope}')->middleware('scope.member')->group(function (): void {
        Route::get('/', [ScopeController::class, 'show']);
        Route::get('/search', [SearchController::class, 'index'])->middleware('scope.actor');
        Route::get('/dashboard', [DashboardController::class, 'show'])->middleware(['scope.actor', 'scope.ability:task.view']);
        Route::get('/contractors/options', [ContractorController::class, 'options']);
        Route::get('/contractors/assignable', [ContractorController::class, 'assignable'])->middleware('scope.ability:task.view');
        Route::get('/contractors', [ContractorController::class, 'index']);
        Route::post('/contractors', [ContractorController::class, 'store']);
        Route::get('/contractors/{contractor}', [ContractorController::class, 'show'])->whereUlid('contractor');
        Route::patch('/contractors/{contractor}', [ContractorController::class, 'update'])->whereUlid('contractor');
        Route::put('/contractors/{contractor}/access', [ContractorController::class, 'updateAccess'])->whereUlid('contractor');
        Route::post('/contractors/{contractor}/scopes', [ContractorController::class, 'addScopes'])->whereUlid('contractor')->middleware('scope.ability:contractor.manage');
        Route::post('/contractors/{contractor}/tokens', [ContractorController::class, 'storeToken'])->whereUlid('contractor');
        Route::delete('/contractors/{contractor}/tokens/{token}', [ContractorController::class, 'destroyToken'])->whereUlid('contractor');
        Route::delete('/contractors/{contractor}', [ContractorController::class, 'destroy'])->whereUlid('contractor');
        Route::post('/contractors/{contractor}/act', [ContractorController::class, 'act'])->whereUlid('contractor')->middleware('scope.ability:contractor.manage');
        Route::get('/projects', [ProjectController::class, 'index'])->middleware(['scope.actor', 'scope.ability:task.view']);
        Route::post('/projects', [ProjectController::class, 'store'])->middleware(['scope.actor', 'scope.ability:task.create']);
        Route::get('/projects/{project}', [ProjectController::class, 'show'])->middleware(['scope.actor', 'scope.ability:task.view']);
        Route::patch('/projects/{project}', [ProjectController::class, 'update'])->middleware(['scope.actor', 'scope.ability:task.update']);
        Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->middleware(['scope.actor', 'scope.ability:project.delete']);
        Route::get('/kpis', [KpiController::class, 'index'])->middleware(['scope.actor', 'scope.ability:task.view']);
        Route::post('/kpis', [KpiController::class, 'store'])->middleware(['scope.actor', 'scope.ability:task.update']);
        Route::get('/kpis/stats', [KpiController::class, 'stats'])->middleware(['scope.actor', 'scope.ability:task.view']);
        Route::get('/kpis/settings', [KpiController::class, 'settings'])->middleware(['scope.actor', 'scope.ability:task.view']);
        Route::put('/kpis/settings', [KpiController::class, 'updateSettings'])->middleware(['scope.actor', 'scope.ability:task.update']);
        Route::patch('/kpis/{kpi}', [KpiController::class, 'update'])->middleware(['scope.actor', 'scope.ability:task.update']);
        Route::delete('/kpis/{kpi}', [KpiController::class, 'destroy'])->middleware(['scope.actor', 'scope.ability:task.update']);
        Route::get('/tasks', [TaskController::class, 'index'])->middleware(['scope.actor', 'scope.ability:task.view']);
        Route::get('/planner', [TaskPlannerController::class, 'index'])->middleware(['scope.actor', 'scope.ability:task.view']);
        Route::post('/planner/tails', [TaskPlannerController::class, 'storeTail'])->middleware(['scope.actor', 'scope.ability:task.update']);
        Route::patch('/planner/tails/{tail}', [TaskPlannerController::class, 'moveTail'])->middleware(['scope.actor', 'scope.ability:task.update']);
        Route::delete('/planner/tails/{tail}', [TaskPlannerController::class, 'destroyTail'])->middleware(['scope.actor', 'scope.ability:task.update']);
        Route::post('/planner/tasks/{task}/copy', [TaskPlannerController::class, 'copyTask'])->middleware(['scope.actor', 'scope.ability:task.create']);
        Route::patch('/planner/tasks/bulk', [TaskPlannerController::class, 'bulk'])->middleware(['scope.actor', 'scope.ability:task.update']);
        Route::post('/tasks', [TaskController::class, 'store'])->middleware(['scope.actor', 'scope.ability:task.create']);
        Route::get('/tasks/search', [TaskController::class, 'search'])->middleware(['scope.actor', 'scope.ability:task.view']);
        Route::delete('/tasks/trash', [TaskController::class, 'purgeTrash'])->middleware(['scope.actor', 'scope.ability:task.delete']);
        Route::get('/tasks/{task}', [TaskController::class, 'show'])->middleware(['scope.actor', 'scope.ability:task.view']);
        Route::patch('/tasks/{task}', [TaskController::class, 'update'])->middleware(['scope.actor', 'scope.ability:task.update']);
        Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->middleware(['scope.actor', 'scope.ability:task.delete']);
        Route::patch('/tasks/{task}/move', [TaskController::class, 'move'])->middleware(['scope.actor', 'scope.ability:task.update']);
        Route::post('/tasks/{task}/detach', [TaskController::class, 'detach'])->middleware(['scope.actor', 'scope.ability:task.update']);
        Route::get('/tasks/{task}/checklist', [TaskChecklistItemController::class, 'index'])->middleware(['scope.actor', 'scope.ability:task.view']);
        Route::post('/tasks/{task}/checklist', [TaskChecklistItemController::class, 'store'])->middleware(['scope.actor', 'scope.ability:task.update']);
        Route::patch('/tasks/{task}/checklist/{item}', [TaskChecklistItemController::class, 'update'])->middleware(['scope.actor', 'scope.ability:task.update']);
        Route::delete('/tasks/{task}/checklist/{item}', [TaskChecklistItemController::class, 'destroy'])->middleware(['scope.actor', 'scope.ability:task.update']);
        Route::post('/tasks/{task}/checklist/{item}/convert-to-subtask', [TaskChecklistItemController::class, 'convertToSubtask'])->middleware(['scope.actor', 'scope.ability:task.create']);
        Route::get('/tasks/{task}/blockers', [TaskBlockerController::class, 'index'])->middleware(['scope.actor', 'scope.ability:task.view']);
        Route::post('/tasks/{task}/blockers', [TaskBlockerController::class, 'store'])->middleware(['scope.actor', 'scope.ability:task.update']);
        Route::patch('/tasks/{task}/blockers/{blocker}/resolve', [TaskBlockerController::class, 'resolve'])->middleware(['scope.actor', 'scope.ability:task.update']);
        Route::get('/tasks/{task}/relations', [TaskRelationController::class, 'index'])->middleware(['scope.actor', 'scope.ability:task.view']);
        Route::post('/tasks/{task}/relations', [TaskRelationController::class, 'store'])->middleware(['scope.actor', 'scope.ability:task.update']);
        Route::delete('/tasks/{task}/relations/{link}', [TaskRelationController::class, 'destroy'])->middleware(['scope.actor', 'scope.ability:task.update']);
        Route::get('/tasks/{task}/comments', [TaskConversationController::class, 'comments'])->middleware(['scope.actor', 'scope.ability:task.view']);
        Route::post('/tasks/{task}/comments', [TaskConversationController::class, 'storeComment'])->middleware(['scope.actor', 'scope.ability:task.update']);
        Route::get('/tasks/{task}/activity', [TaskConversationController::class, 'activity'])->middleware(['scope.actor', 'scope.ability:task.view']);
        Route::get('/facts', [FactController::class, 'index']);
        Route::post('/facts', [FactController::class, 'store']);
        Route::get('/facts/{fact}', [FactController::class, 'show']);
        Route::patch('/facts/{fact}', [FactController::class, 'update']);
        Route::get('/books', [BookController::class, 'index'])->middleware(['scope.actor', 'book.access:book.view']);
        Route::get('/book-spaces', [BookSpaceController::class, 'index'])->middleware(['scope.actor', 'book.access:book.view']);
        Route::post('/book-spaces', [BookSpaceController::class, 'store'])->middleware(['scope.actor', 'book.access:book.create']);
        Route::post('/books', [BookController::class, 'store'])->middleware(['scope.actor', 'book.access:book.create']);
        Route::get('/books/{book}', [BookController::class, 'show'])->middleware(['scope.actor', 'book.access:book.view']);
        Route::patch('/books/{book}', [BookController::class, 'update'])->middleware(['scope.actor', 'book.access:book.update']);
        Route::delete('/books/{book}', [BookController::class, 'destroy'])->middleware(['scope.actor', 'book.access:book.delete']);
        Route::get('/books/{book}/pages', [BookPageController::class, 'index'])->middleware(['scope.actor', 'book.access:book.view']);
        Route::post('/books/{book}/pages', [BookPageController::class, 'store'])->middleware(['scope.actor', 'book.access:book.update']);
        Route::get('/books/{book}/pages/{bookPage}', [BookPageController::class, 'show'])->middleware(['scope.actor', 'book.access:book.view']);
        Route::patch('/books/{book}/pages/{bookPage}', [BookPageController::class, 'update'])->middleware(['scope.actor', 'book.access:book.update']);
        Route::post('/books/{book}/pages/{bookPage}/editing', [BookPageEditingController::class, 'acquire'])->middleware(['scope.actor', 'book.access:book.update']);
        Route::delete('/books/{book}/pages/{bookPage}/editing', [BookPageEditingController::class, 'release'])->middleware(['scope.actor', 'book.access:book.update']);
        Route::post('/books/{book}/pages/{bookPage}/editing/cancel', [BookPageEditingController::class, 'cancel'])->middleware(['scope.actor', 'book.access:book.update']);
        Route::get('/books/{book}/pages/{bookPage}/versions', [BookPageEditingController::class, 'versions'])->middleware(['scope.actor', 'book.access:book.view']);
        Route::get('/books/{book}/pages/{bookPage}/versions/{version}', [BookPageEditingController::class, 'version'])->middleware(['scope.actor', 'book.access:book.view']);
        Route::post('/books/{book}/pages/{bookPage}/versions/{version}/restore', [BookPageEditingController::class, 'restore'])->middleware(['scope.actor', 'book.access:book.update']);
        Route::get('/books/{book}/pages/{bookPage}/blocks', [BookBlockGroupController::class, 'index'])->middleware(['scope.actor', 'book.access:book.view']);
        Route::post('/books/{book}/pages/{bookPage}/blocks', [BookBlockGroupController::class, 'store'])->middleware(['scope.actor', 'book.access:book.update']);
        Route::post('/books/{book}/pages/{bookPage}/blocks/reorder', [BookBlockGroupController::class, 'reorder'])->middleware(['scope.actor', 'book.access:book.update']);
        Route::post('/books/{book}/pages/{bookPage}/blocks/{group}/versions', [BookBlockGroupController::class, 'storeVersion'])->middleware(['scope.actor', 'book.access:book.update']);
        Route::get('/event-types', [EventTypeController::class, 'index']);
        Route::post('/event-types', [EventTypeController::class, 'store']);
        Route::get('/event-sections', [EventSectionController::class, 'index']);
        Route::post('/event-sections', [EventSectionController::class, 'store']);
        Route::get('/events', [EventController::class, 'index']);
        Route::post('/events', [EventController::class, 'store']);
        Route::get('/events/{event}', [EventController::class, 'show']);
        Route::patch('/events/{event}', [EventController::class, 'update']);
        Route::get('/links', [EntityLinkController::class, 'index'])->middleware(['scope.actor', 'scope.ability:task.view']);
        Route::post('/links', [EntityLinkController::class, 'store'])->middleware(['scope.actor', 'scope.ability:task.update']);
        Route::delete('/links/{link}', [EntityLinkController::class, 'destroy'])->middleware(['scope.actor', 'scope.ability:task.update']);
    });
});

Route::prefix('api/contractors')->middleware(['spa.request', 'auth', 'active'])->group(function (): void {
    Route::delete('/acting', [ContractorController::class, 'stopActing']);
});
