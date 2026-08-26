<?php

use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Api\BookBlockGroupController;
use App\Http\Controllers\Api\BookController;
use App\Http\Controllers\Api\BookPageController;
use App\Http\Controllers\Api\EntityLinkController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\EventSectionController;
use App\Http\Controllers\Api\EventTypeController;
use App\Http\Controllers\Api\FactController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ScopeController;
use App\Http\Controllers\Api\TaskController;
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
            Route::get('/tasks', [TaskController::class, 'index']);
            Route::post('/tasks', [TaskController::class, 'store']);
            Route::get('/tasks/{task}', [TaskController::class, 'show']);
            Route::get('/facts', [FactController::class, 'index']);
            Route::post('/facts', [FactController::class, 'store']);
            Route::get('/facts/{fact}', [FactController::class, 'show']);
            Route::get('/books', [BookController::class, 'index']);
            Route::post('/books', [BookController::class, 'store']);
            Route::get('/books/{book}', [BookController::class, 'show']);
            Route::get('/books/{book}/pages', [BookPageController::class, 'index']);
            Route::post('/books/{book}/pages', [BookPageController::class, 'store']);
            Route::get('/books/{book}/pages/{bookPage}', [BookPageController::class, 'show']);
            Route::get('/books/{book}/pages/{bookPage}/blocks', [BookBlockGroupController::class, 'index']);
            Route::post('/books/{book}/pages/{bookPage}/blocks', [BookBlockGroupController::class, 'store']);
            Route::get('/event-types', [EventTypeController::class, 'index']);
            Route::post('/event-types', [EventTypeController::class, 'store']);
            Route::get('/event-sections', [EventSectionController::class, 'index']);
            Route::post('/event-sections', [EventSectionController::class, 'store']);
            Route::get('/events', [EventController::class, 'index']);
            Route::post('/events', [EventController::class, 'store']);
            Route::get('/events/{event}', [EventController::class, 'show']);
            Route::get('/links', [EntityLinkController::class, 'index']);
            Route::post('/links', [EntityLinkController::class, 'store']);
        });
});
