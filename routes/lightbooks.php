<?php

use App\Http\Controllers\Api\BookBlockGroupController;
use App\Http\Controllers\Api\BookConversationController;
use App\Http\Controllers\Api\BookPageController;
use App\Http\Controllers\Api\BookPageEditingController;
use App\Http\Controllers\Api\LightbookController;
use Illuminate\Support\Facades\Route;

Route::get('/', [LightbookController::class, 'index']);
Route::post('/', [LightbookController::class, 'store'])->middleware('book.access:book.create');
Route::get('/{book}', [LightbookController::class, 'show'])->whereUlid('book');
Route::patch('/{book}', [LightbookController::class, 'update'])->whereUlid('book')->middleware('book.access:book.update');
Route::delete('/{book}', [LightbookController::class, 'destroy'])->whereUlid('book')->middleware('book.access:book.delete');
Route::get('/{book}/pages/{bookPage}', [BookPageController::class, 'show'])->whereUlid(['book', 'bookPage']);
Route::patch('/{book}/pages/{bookPage}', [BookPageController::class, 'update'])->whereUlid(['book', 'bookPage'])->middleware('book.access:book.update');
Route::get('/{book}/pages/{bookPage}/comments', [BookConversationController::class, 'comments'])->whereUlid(['book', 'bookPage']);
Route::post('/{book}/pages/{bookPage}/comments', [BookConversationController::class, 'storeComment'])->whereUlid(['book', 'bookPage']);
Route::delete('/{book}/pages/{bookPage}/comments/{comment}', [BookConversationController::class, 'destroy'])->whereUlid(['book', 'bookPage', 'comment']);
Route::post('/{book}/pages/{bookPage}/editing', [BookPageEditingController::class, 'acquire'])->whereUlid(['book', 'bookPage'])->middleware('book.access:book.update');
Route::delete('/{book}/pages/{bookPage}/editing', [BookPageEditingController::class, 'release'])->whereUlid(['book', 'bookPage'])->middleware('book.access:book.update');
Route::post('/{book}/pages/{bookPage}/editing/cancel', [BookPageEditingController::class, 'cancel'])->whereUlid(['book', 'bookPage'])->middleware('book.access:book.update');
Route::get('/{book}/pages/{bookPage}/versions', [BookPageEditingController::class, 'versions'])->whereUlid(['book', 'bookPage']);
Route::get('/{book}/pages/{bookPage}/versions/{version}', [BookPageEditingController::class, 'version'])->whereUlid(['book', 'bookPage', 'version']);
Route::post('/{book}/pages/{bookPage}/versions/{version}/restore', [BookPageEditingController::class, 'restore'])->whereUlid(['book', 'bookPage', 'version'])->middleware('book.access:book.update');
Route::get('/{book}/pages/{bookPage}/blocks', [BookBlockGroupController::class, 'index'])->whereUlid(['book', 'bookPage']);
Route::post('/{book}/pages/{bookPage}/blocks', [BookBlockGroupController::class, 'store'])->whereUlid(['book', 'bookPage'])->middleware('book.access:book.update');
Route::post('/{book}/pages/{bookPage}/blocks/reorder', [BookBlockGroupController::class, 'reorder'])->whereUlid(['book', 'bookPage'])->middleware('book.access:book.update');
Route::post('/{book}/pages/{bookPage}/blocks/{group}/versions', [BookBlockGroupController::class, 'storeVersion'])->whereUlid(['book', 'bookPage', 'group'])->middleware('book.access:book.update');
Route::delete('/{book}/pages/{bookPage}/blocks/{group}', [BookBlockGroupController::class, 'destroy'])->whereUlid(['book', 'bookPage', 'group'])->middleware('book.access:book.update');
