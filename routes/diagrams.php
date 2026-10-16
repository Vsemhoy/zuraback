<?php

use App\Http\Controllers\Api\DiagramController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DiagramController::class, 'index']);
Route::get('/options', [DiagramController::class, 'options']);
Route::get('/targets', [DiagramController::class, 'candidates']);
Route::post('/', [DiagramController::class, 'store'])->middleware('scope.ability:task.create');
Route::get('/{diagram}', [DiagramController::class, 'show'])->whereUlid('diagram');
Route::patch('/{diagram}', [DiagramController::class, 'update'])->whereUlid('diagram')->middleware('scope.ability:task.update');
Route::delete('/{diagram}', [DiagramController::class, 'destroy'])->whereUlid('diagram')->middleware('scope.ability:task.delete');
Route::post('/{diagram}/duplicate', [DiagramController::class, 'duplicate'])->whereUlid('diagram')->middleware('scope.ability:task.create');
Route::get('/{diagram}/revisions', [DiagramController::class, 'revisions'])->whereUlid('diagram');
Route::get('/{diagram}/revisions/{revision}', [DiagramController::class, 'revision'])->whereUlid(['diagram', 'revision']);
Route::post('/{diagram}/attachments', [DiagramController::class, 'attach'])->whereUlid('diagram')->middleware('scope.ability:task.update');
Route::delete('/{diagram}/attachments/{attachment}', [DiagramController::class, 'detach'])->whereUlid(['diagram', 'attachment'])->middleware('scope.ability:task.update');
