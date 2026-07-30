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
use App\Http\Controllers\ChatController;
use App\Http\Controllers\Api\PromoCodeController;
use App\Http\Controllers\Api\QueueController;

// Оверлеи Shell (API для терминалов)
use App\Http\Controllers\Api\ShellApiController;

// Контроллеры Авторизации
use App\Http\Controllers\Auth\SmsAuthController;
use App\Http\Controllers\Auth\AdminLoginController;
use App\Http\Controllers\Auth\LogoutController;

// Контроллеры Админки
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\MapController;
use App\Http\Controllers\Admin\ShiftController;
use App\Http\Controllers\Admin\BonusController;
use App\Http\Controllers\Admin\TaxController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\TariffController;
use App\Http\Controllers\Admin\ZoneController;
use App\Http\Controllers\Admin\LicenseController;
use App\Http\Controllers\Admin\TournamentController;
use App\Http\Controllers\Admin\PromoCodeAdminController;
use App\Http\Controllers\Admin\AchievementAdminController;
use App\Http\Controllers\Admin\OverlayAdminController;
use App\Http\Controllers\Admin\VideoSurveillanceController;
use App\Http\Controllers\Admin\SystemDocsController;
use App\Http\Controllers\Admin\GameRequestAdminController;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\GameRequestController;

/*
|--------------------------------------------------------------------------
| ПУБЛИЧНЫЕ МАРШРУТЫ
|--------------------------------------------------------------------------
*/
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/booking/{slug?}', [ClubController::class, 'show'])->name('booking');
Route::get('/terminal/{slug?}', [TerminalController::class, 'index'])->name('terminal.booking');

Route::match(['get', 'post'], '/api/booking/tariff-grid', [BookingController::class, 'tariffGrid']);
Route::get('/api/booking/tariffs', [BookingController::class, 'tariffsShowcase']);

// Расчёт и доступность — только чтение, доступны гостю: цену и занятые места
// человек должен видеть до входа по SMS. Само бронирование остаётся под auth.
Route::post('/api/booking/computers/availability', [BookingController::class, 'computersAvailability']);
Route::post('/api/booking/games/availability', [BookingController::class, 'availability']);
Route::post('/api/booking/calculate-price', [BookingController::class, 'calculatePrice']);

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
| ОБЩИЕ API (ИГРОКИ + АДМИНЫ)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:web,admin'])->prefix('api/shop')->group(function () {
    Route::get('/products', [ShopController::class, 'getProducts']);
    Route::post('/checkout', [ShopController::class, 'checkout']);
    Route::get('/active-orders', [ShopController::class, 'activeOrders']);
});

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

    Route::get('/shop', [ShopController::class, 'index'])->name('shop');
    Route::post('/api/bonuses/review', [BonusController::class, 'submitReview']);
    Route::post('/api/promo/apply', [PromoCodeController::class, 'apply']);

    // --- РОУТЫ СИСТЕМЫ БРОНИРОВАНИЯ ---
    Route::post('/api/booking/reserve', [BookingController::class, 'reserve']);
    Route::post('/api/booking/{bookingGroup}/cancel', [BookingController::class, 'cancel']);

    Route::post('/api/billing/topup', [BillingController::class, 'topUp']);
    Route::post('/api/admin/call', [ChatController::class, 'callAdmin']);
    Route::get('/api/game-requests/mine', [GameRequestController::class, 'mine']);
    Route::post('/api/game-requests', [GameRequestController::class, 'store']);

    Route::prefix('api/queue')->group(function () {
        Route::post('/join', [QueueController::class, 'join']);
        Route::get('/status', [QueueController::class, 'status']);
        Route::post('/leave', [QueueController::class, 'leave']);
    });
});

/*
|--------------------------------------------------------------------------
| АДМИНКА (REACTOR CONTROL — Guard: Admin)
|--------------------------------------------------------------------------
*/
Route::middleware('guest:admin')->prefix('admin')->group(function () {
    Route::get('/login', [AdminLoginController::class, 'showLoginForm'])->name('admin.login');
    Route::post('/login', [AdminLoginController::class, 'login']);
});

Route::middleware(['auth:admin'])->prefix('admin')->group(function () {

    // ГЛАВНОЕ (все роли: admin / supervisor / owner)
    Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('admin.dashboard');
    Route::post('/give-bonus', [AdminController::class, 'giveBonus']);
    Route::post('/topup', [AdminController::class, 'topUpBalance']);
    Route::get('/search-user', [AdminController::class, 'searchUser']);
    Route::get('/orders', [AdminController::class, 'orders'])->name('admin.orders');
    Route::post('/orders/{id}/status', [AdminController::class, 'updateOrderStatus']);
    Route::get('/shifts/transfer', [ShiftController::class, 'transferPage'])->name('admin.shift.transfer');
    Route::post('/api/shifts/complete', [ShiftController::class, 'completeTransfer']);
    Route::get('/shifts/history', [ShiftController::class, 'history'])->name('admin.shift.history');

    // СКЛАД — обычный админ принимает товар (остаток), каталог — supervisor+
    Route::get('/inventory', [AdminController::class, 'inventory'])->name('admin.inventory');
    Route::prefix('api/inventory')->group(function () {
        Route::get('/products', [AdminController::class, 'listInventoryProducts']);
        Route::post('/receive-scan', [AdminController::class, 'receiveScan']);
        Route::post('/update-stock', [AdminController::class, 'updateStock']);
        Route::get('/find-barcode', [AdminController::class, 'findByBarcode']);
    });

    Route::post('/orders/fulfill-scan', [AdminController::class, 'autoFulfillScan']);
    Route::post('/orders/{id}/fulfill-scan', [AdminController::class, 'fulfillOrderScan']);

    // Инциденты: просмотр всем, закрытие — supervisor+
    Route::get('/incidents', [AdminController::class, 'incidents'])->name('admin.incidents');
    Route::get('/docs', [SystemDocsController::class, 'index'])->name('admin.docs');

    // API АДМИНКИ (операционный контур)
    Route::prefix('api')->group(function () {
        Route::get('/pc-statuses', [AdminController::class, 'getPcStatuses']);
        Route::get('/check-orders', [AdminController::class, 'checkNewOrders']);

        // --- SOS И HID-СИГНАЛЫ С ТЕРМИНАЛОВ ---
        Route::get('/sos-alerts', [AdminController::class, 'sosAlerts']);
        Route::post('/sos-alerts/{id}/ack', [AdminController::class, 'ackSosAlert']);
        Route::post('/input-alerts/{id}/ack', [AdminController::class, 'ackInputAlert']);

        Route::get('/active-calls', [ChatController::class, 'getActiveCalls']);
        Route::post('/calls/{id}/resolve', [ChatController::class, 'resolveCall']);
    });

    // УРОВЕНЬ: SUPERVISOR+
    Route::middleware(['role:supervisor,owner'])->group(function () {
        Route::prefix('api/inventory')->group(function () {
            Route::post('/save', [AdminController::class, 'saveProduct']);
            Route::delete('/delete/{id}', [AdminController::class, 'deleteProduct']);
            Route::post('/write-off', [AdminController::class, 'writeOffUnit']);
        });

        // КАРТА И ТАРИФЫ
        Route::get('/map-builder', fn() => Inertia::render('Admin/MapBuilder', [
            'clubs' => \App\Models\Club::select('id', 'name')->get(),
            'topologyZones' => \App\Models\Zone::select('id', 'name', 'slug', 'color')->orderBy('name')->get(),
        ]))->name('admin.map-builder');
        Route::post('/save-map', [MapController::class, 'save']);
        Route::get('/get-map', [MapController::class, 'getMap']);

        Route::get('/tariffs', [TariffController::class, 'index'])->name('admin.tariffs');
        Route::post('/tariffs', [TariffController::class, 'store']);
        Route::put('/tariffs/{tariff}', [TariffController::class, 'update']);
        Route::delete('/tariffs/{tariff}', [TariffController::class, 'destroy']);
        Route::post('/tariffs/{tariff}/rules', [TariffController::class, 'storeRule']);
        Route::put('/tariff-prices/{tariffPrice}', [TariffController::class, 'updateRule']);
        Route::delete('/tariff-prices/{tariffPrice}', [TariffController::class, 'destroyRule']);
        Route::post('/day-groups', [TariffController::class, 'storeDayGroup']);
        Route::put('/day-groups/{dayGroup}', [TariffController::class, 'updateDayGroup']);
        Route::delete('/day-groups/{dayGroup}', [TariffController::class, 'destroyDayGroup']);
        Route::post('/calendar-overrides', [TariffController::class, 'storeOverride']);
        Route::delete('/calendar-overrides/{calendarDayOverride}', [TariffController::class, 'destroyOverride']);
        Route::post('/addons', [TariffController::class, 'storeAddon']);
        Route::put('/addons/{addon}', [TariffController::class, 'updateAddon']);
        Route::delete('/addons/{addon}', [TariffController::class, 'destroyAddon']);

        Route::get('/zones', [ZoneController::class, 'index'])->name('admin.zones');
        Route::post('/zones', [ZoneController::class, 'store']);
        Route::delete('/zones/{zone}', [ZoneController::class, 'destroy']);

        // ОВЕРЛЕИ
        Route::get('/overlays', [OverlayAdminController::class, 'index'])->name('admin.overlays');

        // ЛИЦЕНЗИИ
        Route::get('/licenses', [LicenseController::class, 'index'])->name('admin.licenses');
        Route::post('/licenses/games', [LicenseController::class, 'storeGame']);
        Route::delete('/licenses/games/{game}', [LicenseController::class, 'destroyGame']);
        Route::post('/licenses/games/{game}/accounts', [LicenseController::class, 'storeAccount']);
        Route::put('/licenses/games/{game}/offers/{club}', [LicenseController::class, 'updateOffer']);
        Route::delete('/licenses/accounts/{account}', [LicenseController::class, 'destroyAccount']);

        // ВИДЕОНАБЛЮДЕНИЕ · МЕТКИ НА ТАЙМЛАЙНЕ
        Route::get('/video-surveillance', [VideoSurveillanceController::class, 'index'])->name('admin.video-surveillance');
        Route::put('/video-surveillance', [VideoSurveillanceController::class, 'updateSettings']);
        Route::post('/video-surveillance/test', [VideoSurveillanceController::class, 'test']);
        Route::post('/video-surveillance/events', [VideoSurveillanceController::class, 'storeEvent']);
        Route::put('/video-surveillance/events/{event}', [VideoSurveillanceController::class, 'updateEvent']);
        Route::delete('/video-surveillance/events/{event}', [VideoSurveillanceController::class, 'destroyEvent']);

        Route::prefix('api')->group(function () {
            Route::post('/incidents/{id}/resolve', [AdminController::class, 'resolveIncident']);
            Route::get('/overlays', [OverlayAdminController::class, 'getOverlays']);
            Route::put('/overlays/{id}', [OverlayAdminController::class, 'updateOverlay']);
            Route::post('/upload-image', [OverlayAdminController::class, 'uploadImage']);
            Route::post('/upload-video', [OverlayAdminController::class, 'uploadVideo']);
        });

        Route::get('/bonuses', [BonusController::class, 'index'])->name('admin.bonuses.index');
        Route::post('/bonuses/settings', [BonusController::class, 'updateSettings'])->name('admin.bonuses.settings');
        Route::post('/bonuses/sync', [BonusController::class, 'sync'])->name('admin.bonuses.sync');
        Route::post('/api/bonuses/verify/{id}', [BonusController::class, 'verify']);
        Route::get('/bonus-logs', [AdminController::class, 'bonusLogs'])->name('admin.bonus-logs');

        // Ивенты
        Route::get('/tournaments', [TournamentController::class, 'index'])->name('admin.tournaments.index');
        Route::post('/tournaments', [TournamentController::class, 'store']);
        Route::patch('/tournaments/{tournament}/status', [TournamentController::class, 'updateStatus']);
        Route::delete('/tournaments/{tournament}', [TournamentController::class, 'destroy']);

        // Промокоды
        Route::get('/promocodes', [PromoCodeAdminController::class, 'index'])->name('admin.promocodes.index');
        Route::post('/promocodes', [PromoCodeAdminController::class, 'store']);
        Route::delete('/promocodes/{promoCode}', [PromoCodeAdminController::class, 'destroy']);

        // Квесты и ачивки
        Route::get('/achievements', [AchievementAdminController::class, 'index'])->name('admin.achievements.index');
        Route::post('/achievements', [AchievementAdminController::class, 'store']);
        Route::put('/achievements/{achievement}', [AchievementAdminController::class, 'update']);
        Route::patch('/achievements/{achievement}/toggle', [AchievementAdminController::class, 'toggle']);
        Route::delete('/achievements/{achievement}', [AchievementAdminController::class, 'destroy']);

        Route::get('/game-requests', [GameRequestAdminController::class, 'index'])->name('admin.game-requests.index');
        Route::patch('/game-requests/{gameRequest}/status', [GameRequestAdminController::class, 'updateStatus']);
        Route::post('/game-requests/bulk-status', [GameRequestAdminController::class, 'bulkStatus']);

        Route::get('/analytics', [AnalyticsController::class, 'index'])->name('admin.analytics.index');
    });

    // УРОВЕНЬ: OWNER
    Route::middleware(['role:owner'])->group(function () {
        Route::get('/taxes', [TaxController::class, 'index'])->name('admin.taxes.index');
        Route::get('/staff', [StaffController::class, 'index'])->name('admin.staff.index');
    });

    Route::post('/logout', [AdminLoginController::class, 'logout'])->name('admin.logout');
});

/*
|--------------------------------------------------------------------------
| API ДЛЯ QML-ШЕЛЛА (Терминалы клуба)
|--------------------------------------------------------------------------
*/
/*
|--------------------------------------------------------------------------
| API ДЛЯ QML-ШЕЛЛА (Терминалы клуба)
|--------------------------------------------------------------------------
*/
Route::prefix('api/shell')->group(function () {
    // --- ПРОВЕРКА И АВТОМАТИЧЕСКАЯ РЕГИСТРАЦИЯ ОБОРУДОВАНИЯ ПО СЕРИЙНИКУ (HWID) ---
    Route::post('/check', [ShellApiController::class, 'checkTerminalBooking']); // Стартовый роут проверки HWID
    Route::post('/register-terminal', [ShellApiController::class, 'registerTerminal']); // Кнопка "Привязать ПК"

    Route::get('/overlays', [ShellApiController::class, 'getActiveOverlays']);
    Route::post('/login', [ShellApiController::class, 'login']);
    Route::get('/balance', [ShellApiController::class, 'getBalance']);
    Route::get('/games', [ShellApiController::class, 'getGames']);
    Route::get('/games/tops', [ShellApiController::class, 'getGameTops']);
    Route::post('/games/record-launch', [ShellApiController::class, 'recordGameLaunch']);

    // --- МАГАЗИН И БАР ДЛЯ ТЕРМИНАЛА ---
    Route::get('/store/products', [ShellApiController::class, 'getProducts']);
    Route::post('/store/checkout', [ShellApiController::class, 'checkout']);
    Route::get('/store/order-status', [ShellApiController::class, 'getOrderStatus']);
    Route::get('/products', [ShellApiController::class, 'getProducts']);
    Route::post('/checkout', [ShellApiController::class, 'checkout']);
    Route::get('/order-status', [ShellApiController::class, 'getOrderStatus']);

    // --- ВЫЗОВ АДМИНА ---
    Route::post('/admin/call', [ShellApiController::class, 'callAdmin']);

    // --- HID мышь/клавиатура (привязка к computers) ---
    Route::post('/hid/snapshot', [ShellApiController::class, 'saveHidSnapshot']);
    Route::post('/hid/alert', [ShellApiController::class, 'reportHidAlert']);

    // --- SOS вызов администратора с причиной ---
    Route::post('/sos', [ShellApiController::class, 'reportSos']);

    // --- УПРАВЛЕНИЕ ЛИЦЕНЗИЯМИ И ОБНОВЛЕНИЕ КЭША VDF ---
    Route::post('/games/take-account', [ShellApiController::class, 'takeAccount']);
    Route::post('/games/free-account', [ShellApiController::class, 'freeAccount']);
    Route::post('/games/update-vdf', [ShellApiController::class, 'updateAccountVdf']); // ТВОЙ НОВЫЙ РОУТ ДЛЯ КЭША!

    // --- ПОСТАНОВКА НА ПАУЗУ С ГЕНЕРАЦИЕЙ НОВОГО ПИНА ---
    Route::post('/games/pause', [ShellApiController::class, 'setPause']);
    Route::post('/games/unpause', [ShellApiController::class, 'clearPause']);

    Route::post('/game-requests', [ShellApiController::class, 'storeGameRequest']);

    // --- CLOUD SAVES: индивидуальные настройки игрока (CS2/Valorant/…) ---
    Route::get('/settings', [ShellApiController::class, 'getCloudSettings']);
    Route::post('/settings', [ShellApiController::class, 'saveCloudSettings']);

    // --- ЗАКРЫТИЕ СЕССИИ (Полное гашение брони ПК в базе клуба) ---
    Route::post('/logout', [ShellApiController::class, 'logout']);
});
