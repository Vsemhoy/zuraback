<?php

use App\Http\Controllers\Api\LedgerController;
use Illuminate\Support\Facades\Route;

Route::get('/flow', [LedgerController::class, 'flow']);
Route::get('/report', [LedgerController::class, 'report']);
Route::post('/transactions/{transaction}/move', [LedgerController::class, 'moveTransaction'])->whereUlid('transaction')->middleware('scope.ability:ledger.write');
Route::get('/options', [LedgerController::class, 'options']);
Route::get('/accounts', [LedgerController::class, 'accounts']);
Route::get('/accounts/{account}/statement', [LedgerController::class, 'accountStatement'])->whereUlid('account');
Route::post('/accounts', [LedgerController::class, 'storeAccount'])->middleware('scope.ability:ledger.manage');
Route::patch('/accounts/{account}', [LedgerController::class, 'updateAccount'])->whereUlid('account')->middleware('scope.ability:ledger.manage');
Route::get('/categories', [LedgerController::class, 'categories']);
Route::post('/categories', [LedgerController::class, 'storeCategory'])->middleware('scope.ability:ledger.manage');
Route::patch('/categories/{category}', [LedgerController::class, 'updateCategory'])->whereUlid('category')->middleware('scope.ability:ledger.manage');
Route::get('/transactions', [LedgerController::class, 'transactions']);
Route::post('/transactions', [LedgerController::class, 'storeTransaction'])->middleware('scope.ability:ledger.write');
Route::get('/transactions/{transaction}', [LedgerController::class, 'show'])->whereUlid('transaction')->withTrashed();
Route::patch('/transactions/{transaction}', [LedgerController::class, 'updateTransaction'])->whereUlid('transaction')->middleware('scope.ability:ledger.write');
Route::delete('/transactions/{transaction}', [LedgerController::class, 'destroyTransaction'])->whereUlid('transaction')->middleware('scope.ability:ledger.manage');
Route::post('/transactions/{transaction}/restore', [LedgerController::class, 'restoreTransaction'])->whereUlid('transaction')->withTrashed()->middleware('scope.ability:ledger.manage');
Route::get('/transactions/{transaction}/history', [LedgerController::class, 'history'])->whereUlid('transaction')->withTrashed();
Route::post('/exploitation/{entryId}', [LedgerController::class, 'linkExploitation'])->whereUlid('entryId')->middleware('scope.ability:ledger.write');
