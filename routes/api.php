<?php

use App\Http\Controllers\Api\ShellApiController;
use Illuminate\Support\Facades\Route;

// Обрати внимание: здесь мы пишем просто 'shell',
// потому что Laravel сам автоматически подставит 'api/' ко всем маршрутам в этом файле!
Route::prefix('shell')->group(function () {
    Route::get('/overlays', [ShellApiController::class, 'getActiveOverlays']);
    Route::post('/login', [ShellApiController::class, 'login']);
});
Route::post('/shell/games/pause', [\App\Http\Controllers\Api\ShellApiController::class, 'setPause']);
