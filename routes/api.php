<?php

use App\Http\Controllers\Api\ShellApiController;
use Illuminate\Support\Facades\Route;

// Обрати внимание: здесь мы пишем просто 'shell',
// потому что Laravel сам автоматически подставит 'api/' ко всем маршрутам в этом файле!
Route::prefix('shell')->group(function () {
    Route::get('/overlays', [ShellApiController::class, 'getActiveOverlays']);
    Route::post('/login', [ShellApiController::class, 'login']);
    Route::get('/balance', [ShellApiController::class, 'getBalance']);
    Route::get('/games', [ShellApiController::class, 'getGames']);
    Route::get('/quick-apps', [ShellApiController::class, 'getQuickApps']);
    Route::get('/games/tops', [ShellApiController::class, 'getGameTops']);
    Route::post('/games/record-launch', [ShellApiController::class, 'recordGameLaunch']);
    Route::post('/hid/snapshot', [ShellApiController::class, 'saveHidSnapshot']);
    Route::post('/hid/alert', [ShellApiController::class, 'reportHidAlert']);
    Route::post('/sos', [ShellApiController::class, 'reportSos']);
    Route::post('/thermal', [ShellApiController::class, 'reportThermal']);
    Route::post('/fan', [ShellApiController::class, 'controlFan']);
    Route::post('/fan/applied', [ShellApiController::class, 'acknowledgeFanApplied']);
    Route::get('/fan', [ShellApiController::class, 'getFanState']);
    Route::get('/fan/discover', [ShellApiController::class, 'discoverFan']);
    Route::post('/fan/bind', [ShellApiController::class, 'bindFan']);
    Route::post('/fan/unbind', [ShellApiController::class, 'unbindFan']);
    Route::post('/ai-assistant', [ShellApiController::class, 'aiAssistant']);
    Route::post('/voice-greeting', [ShellApiController::class, 'voiceGreeting']);
});
