<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\ClubController;
use App\Http\Controllers\Admin\MapController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Auth\SmsAuthController;
use App\Http\Controllers\TerminalController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// 1. Публичные страницы
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/booking/{slug?}', [ClubController::class, 'show'])->name('booking');

// 2. Страница авторизации (Вьюшка)
Route::get('/login', function () {
    return Inertia::render('Auth/Login');
})->name('login');

// 3. API Логика авторизации
Route::post('/auth/send-code', [SmsAuthController::class, 'sendCode'])->name('auth.send-code');
Route::post('/auth/verify-code', [SmsAuthController::class, 'verifyCode'])->name('auth.verify-code');
Route::post('/logout', [SmsAuthController::class, 'logout'])->name('logout');

// 4. Защищенные маршруты (Только для авторизованных)
Route::middleware('auth')->group(function () {
    // Дашборд теперь защищен. Гость сюда не зайдет.
    Route::get('/auth/dashboard', [ProfileController::class, 'index'])->name('dashboard');
    Route::get('/auth/profile', [ProfileController::class, 'index'])->name('profile');
});
Route::get('/terminal/{slug?}', [TerminalController::class, 'index'])->name('terminal.booking');
// 5. Админка
Route::get('/admin/map-builder', function () {
    return Inertia::render('Admin/MapBuilder', [
        'clubs' => \App\Models\Club::select('id', 'name')->get()
    ]);
});

Route::prefix('admin')->group(function () {
    Route::post('/save-map', [MapController::class, 'save']);
    Route::get('/get-map', [MapController::class, 'getMap']);
});
