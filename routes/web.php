<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\ClubController;
use App\Http\Controllers\Admin\MapController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Auth\SmsAuthController;
use App\Http\Controllers\TerminalController;
use App\Http\Controllers\GizmoController;
use App\Http\Controllers\Auth\AdminLoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\ShopController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Публичные маршруты
|--------------------------------------------------------------------------
*/
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/booking/{slug?}', [ClubController::class, 'show'])->name('booking');
Route::get('/terminal/{slug?}', [TerminalController::class, 'index'])->name('terminal.booking');

/*
|--------------------------------------------------------------------------
| Авторизация ИГРОКОВ (Users)
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', fn() => Inertia::render('Auth/Login'))->name('login');
    Route::post('/auth/send-code', [SmsAuthController::class, 'sendCode'])->name('auth.send-code');
    Route::post('/auth/verify-code', [SmsAuthController::class, 'verifyCode'])->name('auth.verify-code');
});

// УНИВЕРСАЛЬНЫЙ ВЫХОД (Оставил только один, правильный, который мы делали)
Route::post('/logout', [LogoutController::class, 'logout'])->name('logout');

/*
|--------------------------------------------------------------------------
| Личный кабинет ИГРОКА
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {

    // ДОБАВЛЕН РЕДИРЕКТ С ОШИБОЧНОГО ПУТИ НА ПРАВИЛЬНЫЙ
    Route::redirect('/auth/profile', '/account/dashboard');

    Route::prefix('account')->group(function () {
        Route::get('/dashboard', [ProfileController::class, 'dashboard'])->name('dashboard');
        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    });

    Route::prefix('api/gizmo')->group(function () {
        Route::get('/profile', [GizmoController::class, 'getUserProfile']);
        Route::get('/computers', [GizmoController::class, 'getComputersStatus']);
        Route::get('/history', [GizmoController::class, 'getTransactionHistory']);
        Route::post('/start', [GizmoController::class, 'startSession']);
        Route::post('/stop', [GizmoController::class, 'stopSession']);
        Route::post('/deposit', [GizmoController::class, 'deposit']);
    });
});

/*
|--------------------------------------------------------------------------
| Админка (REACTOR Control)
|--------------------------------------------------------------------------
*/

// Для неавторизованных админов
Route::middleware('guest:admin')->prefix('admin')->group(function () {
    Route::get('/login', [AdminLoginController::class, 'showLoginForm'])->name('admin.login');
    Route::post('/login', [AdminLoginController::class, 'login']);
});

// Для авторизованных админов
Route::middleware(['auth:admin', 'admin'])->prefix('admin')->group(function () {

    // Оставляем это, если админская форма логина жестко завязана на этот роут.
    // Но кнопка "Выйти" в AdminLayout будет бить в универсальный /logout.
    Route::post('/logout', [AdminLoginController::class, 'logout'])->name('admin.logout');

    Route::get('/map-builder', function () {
        return Inertia::render('Admin/MapBuilder', [
            'clubs' => \App\Models\Club::select('id', 'name')->get()
        ]);
    })->name('admin.map-builder');

    Route::post('/save-map', [MapController::class, 'save']);
    Route::get('/get-map', [MapController::class, 'getMap']);
});

Route::middleware('auth')->group(function () {
    Route::post('/api/billing/topup', [BillingController::class, 'topUp']);
    Route::post('/api/billing/start-session', [BillingController::class, 'startSession']);
});


Route::prefix('admin')->middleware(['auth'])->group(function () {
    Route::get('/', [AdminController::class, 'index'])->name('admin.dashboard');
    Route::get('/search-user', [AdminController::class, 'searchUser']);
    Route::post('/manual-deposit', [AdminController::class, 'manualDeposit']);
});
Route::prefix('admin/fiscal')->middleware(['auth'])->group(function () {
    Route::get('/', [AdminController::class, 'fiscalMonitor'])->name('admin.fiscal');
    Route::get('/hardware-status', [AdminController::class, 'getKktHardwareStatus']);
});


Route::prefix('api')->middleware(['auth'])->group(function () {
    Route::post('/booking/reserve', [BookingController::class, 'reserve']);
});


Route::middleware(['auth'])->group(function () {
    Route::get('/shop', function () {
        return inertia('User/Shop'); // Путь к Shop.vue
    })->name('shop');

    Route::get('/api/shop/products', [ShopController::class, 'getProducts']);
    Route::post('/api/shop/checkout', [ShopController::class, 'checkout']);
});
