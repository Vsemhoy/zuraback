<?php

use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Api\BookBlockGroupController;
use App\Http\Controllers\Api\BookController;
use App\Http\Controllers\Api\BookSpaceController;
use App\Http\Controllers\Api\BookPageController;
use App\Http\Controllers\Api\BookPageEditingController;
use App\Http\Controllers\Api\EntityLinkController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\EventSectionController;
use App\Http\Controllers\Api\EventTypeController;
use App\Http\Controllers\Api\FactController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ScopeController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TaskChecklistItemController;
use App\Http\Controllers\Api\TaskBlockerController;
use App\Http\Controllers\Api\TaskRelationController;
use App\Http\Controllers\Api\TaskConversationController;
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
            Route::get('/projects', [ProjectController::class, 'index']);
            Route::post('/projects', [ProjectController::class, 'store']);
            Route::get('/projects/{project}', [ProjectController::class, 'show']);
            Route::patch('/projects/{project}', [ProjectController::class, 'update']);
            Route::get('/tasks', [TaskController::class, 'index']);
            Route::post('/tasks', [TaskController::class, 'store']);
            Route::get('/tasks/search', [TaskController::class, 'search']);
            Route::get('/tasks/{task}', [TaskController::class, 'show']);
            Route::patch('/tasks/{task}', [TaskController::class, 'update']);
            Route::patch('/tasks/{task}/move', [TaskController::class, 'move']);
            Route::post('/tasks/{task}/detach', [TaskController::class, 'detach']);
            Route::get('/tasks/{task}/checklist', [TaskChecklistItemController::class, 'index']);
            Route::post('/tasks/{task}/checklist', [TaskChecklistItemController::class, 'store']);
            Route::patch('/tasks/{task}/checklist/{item}', [TaskChecklistItemController::class, 'update']);
            Route::delete('/tasks/{task}/checklist/{item}', [TaskChecklistItemController::class, 'destroy']);
            Route::post('/tasks/{task}/checklist/{item}/convert-to-subtask', [TaskChecklistItemController::class, 'convertToSubtask']);
            Route::get('/tasks/{task}/blockers', [TaskBlockerController::class, 'index']);
            Route::post('/tasks/{task}/blockers', [TaskBlockerController::class, 'store']);
            Route::patch('/tasks/{task}/blockers/{blocker}/resolve', [TaskBlockerController::class, 'resolve']);
            Route::get('/tasks/{task}/relations', [TaskRelationController::class, 'index']);
            Route::post('/tasks/{task}/relations', [TaskRelationController::class, 'store']);
            Route::delete('/tasks/{task}/relations/{link}', [TaskRelationController::class, 'destroy']);
            Route::get('/tasks/{task}/comments', [TaskConversationController::class, 'comments']);
            Route::post('/tasks/{task}/comments', [TaskConversationController::class, 'storeComment']);
            Route::get('/tasks/{task}/activity', [TaskConversationController::class, 'activity']);
            Route::get('/facts', [FactController::class, 'index']);
            Route::post('/facts', [FactController::class, 'store']);
            Route::get('/facts/{fact}', [FactController::class, 'show']);
            Route::patch('/facts/{fact}', [FactController::class, 'update']);
            Route::get('/books', [BookController::class, 'index']);
            Route::get('/book-spaces', [BookSpaceController::class, 'index']);
            Route::post('/book-spaces', [BookSpaceController::class, 'store']);
            Route::post('/books', [BookController::class, 'store']);
            Route::get('/books/{book}', [BookController::class, 'show']);
            Route::patch('/books/{book}', [BookController::class, 'update']);
            Route::get('/books/{book}/pages', [BookPageController::class, 'index']);
            Route::post('/books/{book}/pages', [BookPageController::class, 'store']);
            Route::get('/books/{book}/pages/{bookPage}', [BookPageController::class, 'show']);
            Route::patch('/books/{book}/pages/{bookPage}', [BookPageController::class, 'update']);
            Route::post('/books/{book}/pages/{bookPage}/editing', [BookPageEditingController::class, 'acquire']);
            Route::delete('/books/{book}/pages/{bookPage}/editing', [BookPageEditingController::class, 'release']);
            Route::post('/books/{book}/pages/{bookPage}/editing/cancel', [BookPageEditingController::class, 'cancel']);
            Route::get('/books/{book}/pages/{bookPage}/versions', [BookPageEditingController::class, 'versions']);
            Route::get('/books/{book}/pages/{bookPage}/versions/{version}', [BookPageEditingController::class, 'version']);
            Route::get('/books/{book}/pages/{bookPage}/blocks', [BookBlockGroupController::class, 'index']);
            Route::post('/books/{book}/pages/{bookPage}/blocks', [BookBlockGroupController::class, 'store']);
            Route::post('/books/{book}/pages/{bookPage}/blocks/reorder', [BookBlockGroupController::class, 'reorder']);
            Route::post('/books/{book}/pages/{bookPage}/blocks/{group}/versions', [BookBlockGroupController::class, 'storeVersion']);
            Route::get('/event-types', [EventTypeController::class, 'index']);
            Route::post('/event-types', [EventTypeController::class, 'store']);
            Route::get('/event-sections', [EventSectionController::class, 'index']);
            Route::post('/event-sections', [EventSectionController::class, 'store']);
            Route::get('/events', [EventController::class, 'index']);
            Route::post('/events', [EventController::class, 'store']);
            Route::get('/events/{event}', [EventController::class, 'show']);
            Route::patch('/events/{event}', [EventController::class, 'update']);
            Route::get('/links', [EntityLinkController::class, 'index']);
            Route::post('/links', [EntityLinkController::class, 'store']);
        });
});
