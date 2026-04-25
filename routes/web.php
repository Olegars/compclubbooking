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
use App\Http\Controllers\Admin\ShiftController;
use App\Http\Controllers\Admin\BonusController;
use App\Http\Controllers\Admin\TaxController;    // <-- НОВОЕ: Налоги
use App\Http\Controllers\Admin\StaffController; // <-- НОВОЕ: Персонал

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
| АВТОРИЗАЦИЯ ИГРОКОВ (SMS)
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', fn() => Inertia::render('Auth/Login'))->name('login');
    Route::post('/auth/send-code', [SmsAuthController::class, 'sendCode'])->name('auth.send-code');
    Route::post('/auth/verify-code', [SmsAuthController::class, 'verifyCode'])->name('auth.verify-code');
});

Route::post('/logout', [LogoutController::class, 'logout'])->name('logout');

/*
|--------------------------------------------------------------------------
| ЛИЧНЫЙ КАБИНЕТ ИГРОКА (Guard: Web)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth'])->group(function () {
    Route::redirect('/auth/profile', '/account/dashboard');

    Route::prefix('account')->group(function () {
        Route::get('/dashboard', [ProfileController::class, 'dashboard'])->name('dashboard');
        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    });

    // Магазин
    Route::get('/shop', [ShopController::class, 'index'])->name('shop');
    Route::prefix('api/shop')->group(function () {
        Route::get('/products', [ShopController::class, 'getProducts']);
        Route::post('/checkout', [ShopController::class, 'checkout']);
    });

    // Бонусы (Отправка отзыва)
    Route::post('/api/bonuses/review', [BonusController::class, 'submitReview']);

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
| АДМИНКА (REACTOR CONTROL — Guard: Admin)
|--------------------------------------------------------------------------
*/

// Логин для админов
Route::middleware('guest:admin')->prefix('admin')->group(function () {
    Route::get('/login', [AdminLoginController::class, 'showLoginForm'])->name('admin.login');
    Route::post('/login', [AdminLoginController::class, 'login']);
});

// Защищенные роуты админки
Route::middleware(['auth:admin'])->prefix('admin')->group(function () {

    // ==========================================
    // УРОВЕНЬ 1: ДОСТУПНО ВСЕМ (admin, supervisor, owner)
    // Обычные линейные админы за стойкой
    // ==========================================

    // Дашборд и базовые функции
    Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('admin.dashboard');
    Route::post('/give-bonus', [AdminController::class, 'giveBonus']);
    Route::get('/search-user', [AdminController::class, 'searchUser']);

    // Заказы (Админы должны их собирать и выдавать)
    Route::get('/orders', [AdminController::class, 'orders'])->name('admin.orders');
    Route::post('/orders/{id}/status', [AdminController::class, 'updateOrderStatus']);

    // Смены (Прием и сдача кассы)
    Route::get('/shifts/transfer', [ShiftController::class, 'transferPage'])->name('admin.shift.transfer');
    Route::post('/api/shifts/complete', [ShiftController::class, 'completeTransfer']);
    Route::get('/shifts/history', [ShiftController::class, 'history'])->name('admin.shift.history');

    // Карта и инциденты
    Route::get('/incidents', [AdminController::class, 'incidents'])->name('admin.incidents');
    Route::get('/map-builder', function () {
        return Inertia::render('Admin/MapBuilder', [
            'clubs' => \App\Models\Club::select('id', 'name')->get()
        ]);
    })->name('admin.map-builder');
    Route::post('/save-map', [MapController::class, 'save']);
    Route::get('/get-map', [MapController::class, 'getMap']);

    // Системные API
    Route::prefix('api')->group(function () {
        Route::get('/pc-statuses', [AdminController::class, 'getPcStatuses']);
        Route::get('/check-orders', [AdminController::class, 'checkNewOrders']);
        Route::post('/incidents/{id}/resolve', [AdminController::class, 'resolveIncident']);
    });


    // ==========================================
    // УРОВЕНЬ 2: СУПЕРВИЗОРЫ И ВЛАДЕЛЕЦ (supervisor, owner)
    // ==========================================
    Route::middleware(['role:supervisor,owner'])->group(function () {

        // Склад (Приемка товара, инвентаризация)
        Route::get('/inventory', [AdminController::class, 'inventory'])->name('admin.inventory');
        Route::prefix('api/inventory')->group(function () {
            Route::post('/save', [AdminController::class, 'saveProduct']);
            Route::delete('/delete/{id}', [AdminController::class, 'deleteProduct']);
            Route::post('/update-stock', [AdminController::class, 'updateStock']);
            Route::get('/find-barcode', [AdminController::class, 'findByBarcode']);
        });

        // Бонусный центр (Модерация отзывов)
        Route::get('/bonuses', [BonusController::class, 'index'])->name('admin.bonuses.index');
        Route::post('/api/bonuses/verify/{id}', [BonusController::class, 'verify']);
        Route::get('/bonus-logs', [AdminController::class, 'bonusLogs'])->name('admin.bonus-logs');
    });


    // ==========================================
    // УРОВЕНЬ 3: ТОЛЬКО ВЛАДЕЛЕЦ (owner)
    // ==========================================
    Route::middleware(['role:owner'])->group(function () {

        // Налоговый модуль (УСН, Взносы)
        Route::get('/taxes', [TaxController::class, 'index'])->name('admin.taxes.index');

        // Управление персоналом и зарплатами
        Route::get('/staff', [StaffController::class, 'index'])->name('admin.staff.index');
    });

    // ВЫХОД (Для всех)
    Route::post('/logout', [AdminLoginController::class, 'logout'])->name('admin.logout');
});
