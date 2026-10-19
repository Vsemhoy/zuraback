<?php

use App\Http\Controllers\Api\ContentNavigationController;
use Illuminate\Support\Facades\Route;

Route::get('/targets', [ContentNavigationController::class, 'candidates']);
Route::get('/{type}/{id}', [ContentNavigationController::class, 'show'])->whereIn('type', ['diagram', 'file', 'lore', 'task', 'lightbook'])->whereUlid('id');
Route::post('/{type}/{id}/links', [ContentNavigationController::class, 'store'])->whereIn('type', ['diagram', 'file', 'lore', 'task', 'lightbook'])->whereUlid('id');
Route::patch('/{type}/{id}/links/{link}', [ContentNavigationController::class, 'update'])->whereIn('type', ['diagram', 'file', 'lore', 'task', 'lightbook'])->whereUlid(['id', 'link']);
Route::delete('/{type}/{id}/links/{link}', [ContentNavigationController::class, 'destroy'])->whereIn('type', ['diagram', 'file', 'lore', 'task', 'lightbook'])->whereUlid(['id', 'link']);
