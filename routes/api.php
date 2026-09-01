<?php

use App\Http\Controllers\Api\AgentTaskController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ScopeController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TaskConversationController;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('agent')->middleware(['auth:sanctum', 'active', 'agent'])->group(function (): void {
    Route::get('/me', fn (Request $request): UserResource => new UserResource($request->user()));
    Route::get('/tasks', [AgentTaskController::class, 'index']);
    Route::get('/scopes', [ScopeController::class, 'index']);

    Route::prefix('/scopes/{scope}')->middleware('scope.member')->group(function (): void {
        Route::get('/projects', [ProjectController::class, 'index'])->middleware('scope.ability:task.view');
        Route::get('/tasks', [TaskController::class, 'index'])->middleware('scope.ability:task.view');
        Route::post('/tasks', [TaskController::class, 'store'])->middleware('scope.ability:task.create');
        Route::get('/tasks/search', [TaskController::class, 'search'])->middleware('scope.ability:task.view');
        Route::get('/tasks/{task}', [TaskController::class, 'show'])->middleware('scope.ability:task.view');
        Route::patch('/tasks/{task}', [TaskController::class, 'update'])->middleware('scope.ability:task.update');
        Route::get('/tasks/{task}/comments', [TaskConversationController::class, 'comments'])->middleware('scope.ability:task.view');
        Route::post('/tasks/{task}/comments', [TaskConversationController::class, 'storeComment'])->middleware('scope.ability:task.update');
        Route::get('/tasks/{task}/activity', [TaskConversationController::class, 'activity'])->middleware('scope.ability:task.view');
    });
});
