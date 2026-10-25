<?php

use App\Http\Controllers\Api\ContentStructureController;
use Illuminate\Support\Facades\Route;

Route::prefix('content-structure/{module}')->where(['module' => 'eventor|lightbook'])->middleware(['scope.actor', 'throttle:90,1'])->group(function (): void {
    Route::get('/', [ContentStructureController::class, 'index']);
    Route::post('/{kind}', [ContentStructureController::class, 'store'])->where('kind', 'sections|categories');
    Route::patch('/{kind}/{item}', [ContentStructureController::class, 'update'])->where('kind', 'sections|categories')->whereUlid('item');
    Route::delete('/{kind}/{item}', [ContentStructureController::class, 'destroy'])->where('kind', 'sections|categories')->whereUlid('item');
});
