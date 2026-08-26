<?php

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
