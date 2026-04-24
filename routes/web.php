<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Контроллеры Игроков
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ClubController;
use App\Http\Controllers\TerminalController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\GizmoController;

// Контроллеры Авторизации
use App\Http\Controllers\Auth\SmsAuthController;
use App\Http\Controllers\Auth\AdminLoginController;
use App\Http\Controllers\Auth\LogoutController;

// Контроллеры Админки
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\MapController;

/*
|--------------------------------------------------------------------------
| ПУБЛИЧНЫЕ МАРШРУТЫ
|--------------------------------------------------------------------------
*/
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/booking/{slug?}', [ClubController::class, 'show'])->name('booking');
Route::get('/terminal/{slug?}', [TerminalController::class, 'index'])->name('terminal.booking');

/*
|--------------------------------------------------------------------------
| АВТОРИЗАЦИЯ ИГРОКОВ (SMS / Users)
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', fn() => Inertia::render('Auth/Login'))->name('login');
    Route::post('/auth/send-code', [SmsAuthController::class, 'sendCode'])->name('auth.send-code');
    Route::post('/auth/verify-code', [SmsAuthController::class, 'verifyCode'])->name('auth.verify-code');
});

// Универсальный выход (сработает для всех)
Route::post('/logout', [LogoutController::class, 'logout'])->name('logout');

/*
|--------------------------------------------------------------------------
| ЛИЧНЫЙ КАБИНЕТ ИГРОКА (Auth Guard: Web)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth'])->group(function () {

    // Редирект со старых путей
    Route::redirect('/auth/profile', '/account/dashboard');

    // Кабинет и профиль
    Route::prefix('account')->group(function () {
        Route::get('/dashboard', [ProfileController::class, 'dashboard'])->name('dashboard');
        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    });

    // Магазин (Reactor Market)
    Route::get('/shop', fn() => inertia('User/Shop'))->name('shop');
    Route::prefix('api/shop')->group(function () {
        Route::get('/products', [ShopController::class, 'getProducts']);
        Route::post('/checkout', [ShopController::class, 'checkout']);
    });

    // Бронирование и Биллинг
    Route::post('/api/booking/reserve', [BookingController::class, 'reserve']);
    Route::post('/api/billing/topup', [BillingController::class, 'topUp']);
    Route::post('/api/billing/start-session', [BillingController::class, 'startSession']);

    // Интеграция с Gizmo API
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
| АДМИНКА (REACTOR CONTROL — Auth Guard: Admin)
|--------------------------------------------------------------------------
*/

// Логин для админов (Email + Password)
Route::middleware('guest:admin')->prefix('admin')->group(function () {
    Route::get('/login', [AdminLoginController::class, 'showLoginForm'])->name('admin.login');
    Route::post('/login', [AdminLoginController::class, 'login']);
});

// Защищенные роуты админки
// Защищенные роуты админки
Route::middleware(['auth:admin', 'admin'])->prefix('admin')->group(function () {

    // 1. ДАШБОАРД (Мониторинг и выдача бонусов)
    Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('admin.dashboard');
    Route::post('/give-bonus', [AdminController::class, 'giveBonus']);
    Route::get('/search-user', [AdminController::class, 'searchUser']); // Поиск для дашбоарда

    // 2. ОЧЕРЕДЬ ЗАКАЗОВ
    Route::get('/orders', [AdminController::class, 'orders'])->name('admin.orders');
    Route::post('/orders/{id}/status', [AdminController::class, 'updateOrderStatus']);

    // 3. СКЛАД МАРКЕТА (Инвентарь)
    Route::get('/inventory', [AdminController::class, 'inventory'])->name('admin.inventory');
    Route::post('/api/inventory/save', [AdminController::class, 'saveProduct']);
    Route::delete('/api/inventory/delete/{id}', [AdminController::class, 'deleteProduct']);

    // 4. РЕЕСТР БОНУСОВ (Логи компенсаций)
    Route::get('/bonus-logs', [AdminController::class, 'bonusLogs'])->name('admin.bonus-logs');

    // 5. РЕДАКТОР КАРТЫ
    Route::get('/map-builder', function () {
        return Inertia::render('Admin/MapBuilder', [
            'clubs' => \App\Models\Club::select('id', 'name')->get()
        ]);
    })->name('admin.map-builder');
    Route::post('/save-map', [MapController::class, 'save']);
    Route::get('/get-map', [MapController::class, 'getMap']);

    // 6. ФИСКАЛЬНЫЙ МОНИТОР
    Route::prefix('fiscal')->group(function () {
        Route::get('/', [AdminController::class, 'fiscalMonitor'])->name('admin.fiscal');
        Route::get('/hardware-status', [AdminController::class, 'getKktHardwareStatus']);
    });

    // ВЫХОД
    Route::post('/logout', [AdminLoginController::class, 'logout'])->name('admin.logout');
});
Route::get('/orders', [AdminController::class, 'orders'])->name('admin.orders');
Route::post('/orders/{id}/status', [AdminController::class, 'updateOrderStatus']);
