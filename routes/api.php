<?php

use App\Http\Controllers\Api\AgentBookerImportController;
use App\Http\Controllers\Api\AgentSpecificationController;
use App\Http\Controllers\Api\AgentTaskController;
use App\Http\Controllers\Api\AgentTaskerImportController;
use App\Http\Controllers\Api\BookBlockGroupController;
use App\Http\Controllers\Api\BookController;
use App\Http\Controllers\Api\BookConversationController;
use App\Http\Controllers\Api\BookPageController;
use App\Http\Controllers\Api\BookPageEditingController;
use App\Http\Controllers\Api\ContractorController;
use App\Http\Controllers\Api\EntityLinkController;
use App\Http\Controllers\Api\FactController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ScopeController;
use App\Http\Controllers\Api\TaskChecklistItemController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TaskConversationController;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('agent')->middleware(['auth:sanctum', 'active', 'agent'])->group(function (): void {
    Route::get('/spec', [AgentSpecificationController::class, 'show']);
    Route::get('/me', fn (Request $request): UserResource => new UserResource($request->user()));
    Route::get('/tasks', [AgentTaskController::class, 'index']);
    Route::get('/scopes', [ScopeController::class, 'index']);

    Route::prefix('/scopes/{scope}')->middleware('scope.member')->group(function (): void {
        Route::get('/contractors/assignable', [ContractorController::class, 'assignable'])->middleware('scope.ability:task.view');
        Route::get('/projects', [ProjectController::class, 'index'])->middleware('scope.ability:task.view');
        Route::post('/projects', [ProjectController::class, 'store'])->middleware('scope.ability:task.create');
        Route::patch('/projects/reorder', [ProjectController::class, 'reorder'])->middleware('scope.ability:task.update');
        Route::get('/projects/{project}', [ProjectController::class, 'show'])->middleware('scope.ability:task.view');
        Route::patch('/projects/{project}', [ProjectController::class, 'update'])->middleware('scope.ability:task.update');
        Route::post('/facts', [FactController::class, 'store'])->middleware('scope.ability:task.create');
        Route::post('/links', [EntityLinkController::class, 'store'])->middleware('scope.ability:task.update');
        Route::post('/imports/tasker', [AgentTaskerImportController::class, 'store'])->middleware(['scope.ability:task.create', 'scope.ability:task.update']);
        Route::post('/imports/booker', [AgentBookerImportController::class, 'store'])->middleware(['scope.ability:book.create', 'scope.ability:book.update']);
        Route::get('/books', [BookController::class, 'index'])->middleware('book.access:book.view');
        Route::post('/books', [BookController::class, 'store'])->middleware('book.access:book.create');
        Route::get('/books/{book}', [BookController::class, 'show'])->middleware('book.access:book.view');
        Route::patch('/books/{book}', [BookController::class, 'update'])->middleware('book.access:book.update');
        Route::delete('/books/{book}', [BookController::class, 'destroy'])->middleware('book.access:book.delete');
        Route::get('/books/{book}/pages', [BookPageController::class, 'index'])->middleware('book.access:book.view');
        Route::post('/books/{book}/pages', [BookPageController::class, 'store'])->middleware('book.access:book.update');
        Route::get('/books/{book}/pages/{bookPage}', [BookPageController::class, 'show'])->middleware('book.access:book.view');
        Route::patch('/books/{book}/pages/{bookPage}', [BookPageController::class, 'update'])->middleware('book.access:book.update');
        Route::get('/books/{book}/pages/{bookPage}/comments', [BookConversationController::class, 'comments'])->middleware('book.access:book.view');
        Route::post('/books/{book}/pages/{bookPage}/comments', [BookConversationController::class, 'storeComment'])->middleware('book.access:book.view');
        Route::delete('/books/{book}/pages/{bookPage}/comments/{comment}', [BookConversationController::class, 'destroy'])->middleware('book.access:book.view');
        Route::post('/books/{book}/pages/{bookPage}/editing', [BookPageEditingController::class, 'acquire'])->middleware('book.access:book.update');
        Route::delete('/books/{book}/pages/{bookPage}/editing', [BookPageEditingController::class, 'release'])->middleware('book.access:book.update');
        Route::post('/books/{book}/pages/{bookPage}/editing/cancel', [BookPageEditingController::class, 'cancel'])->middleware('book.access:book.update');
        Route::get('/books/{book}/pages/{bookPage}/versions', [BookPageEditingController::class, 'versions'])->middleware('book.access:book.view');
        Route::get('/books/{book}/pages/{bookPage}/versions/{version}', [BookPageEditingController::class, 'version'])->middleware('book.access:book.view');
        Route::post('/books/{book}/pages/{bookPage}/versions/{version}/restore', [BookPageEditingController::class, 'restore'])->middleware('book.access:book.update');
        Route::get('/books/{book}/pages/{bookPage}/blocks', [BookBlockGroupController::class, 'index'])->middleware('book.access:book.view');
        Route::post('/books/{book}/pages/{bookPage}/blocks', [BookBlockGroupController::class, 'store'])->middleware('book.access:book.update');
        Route::post('/books/{book}/pages/{bookPage}/blocks/reorder', [BookBlockGroupController::class, 'reorder'])->middleware('book.access:book.update');
        Route::post('/books/{book}/pages/{bookPage}/blocks/{group}/versions', [BookBlockGroupController::class, 'storeVersion'])->middleware('book.access:book.update');
        Route::get('/tasks', [TaskController::class, 'index'])->middleware('scope.ability:task.view');
        Route::post('/tasks', [TaskController::class, 'store'])->middleware('scope.ability:task.create');
        Route::get('/tasks/search', [TaskController::class, 'search'])->middleware('scope.ability:task.view');
        Route::get('/tasks/{task}', [TaskController::class, 'show'])->middleware('scope.ability:task.view');
        Route::patch('/tasks/{task}', [TaskController::class, 'update'])->middleware('scope.ability:task.update');
        Route::get('/tasks/{task}/checklist', [TaskChecklistItemController::class, 'index'])->middleware('scope.ability:task.view');
        Route::post('/tasks/{task}/checklist', [TaskChecklistItemController::class, 'store'])->middleware('scope.ability:task.update');
        Route::patch('/tasks/{task}/checklist/{item}', [TaskChecklistItemController::class, 'update'])->middleware('scope.ability:task.update');
        Route::get('/tasks/{task}/comments', [TaskConversationController::class, 'comments'])->middleware('scope.ability:task.view');
        Route::post('/tasks/{task}/comments', [TaskConversationController::class, 'storeComment'])->middleware('scope.ability:task.update');
        Route::delete('/tasks/{task}/comments/{comment}', [TaskConversationController::class, 'destroyComment'])->middleware('scope.ability:task.update');
        Route::get('/tasks/{task}/activity', [TaskConversationController::class, 'activity'])->middleware('scope.ability:task.view');
    });
});
