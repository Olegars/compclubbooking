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
use App\Http\Controllers\Api\WolRelayController;
use App\Http\Controllers\Api\SharedFanRelayController;
use App\Http\Controllers\Api\ShellIsolateRelayController;
use App\Http\Controllers\Api\WifiGrantRelayController;
use App\Http\Controllers\Api\KitchenPrintRelayController;
use App\Http\Controllers\Api\VideoMarkerRelayController;
use App\Http\Controllers\WifiAccessController;

// Контроллеры Авторизации
use App\Http\Controllers\Auth\SmsAuthController;
use App\Http\Controllers\Auth\AdminLoginController;
use App\Http\Controllers\Auth\LogoutController;

// Контроллеры Админки
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\MapController;
use App\Http\Controllers\Admin\FanAdminController;
use App\Http\Controllers\Admin\LightAdminController;
use App\Http\Controllers\Admin\ShiftController;
use App\Http\Controllers\Admin\BonusController;
use App\Http\Controllers\Admin\TaxController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\StaffPayrollController;
use App\Http\Controllers\Admin\TariffController;
use App\Http\Controllers\Admin\ZoneController;
use App\Http\Controllers\Admin\LicenseController;
use App\Http\Controllers\Admin\QuickAppController;
use App\Http\Controllers\Admin\TournamentController;
use App\Http\Controllers\Admin\PromoCodeAdminController;
use App\Http\Controllers\Admin\AchievementAdminController;
use App\Http\Controllers\Admin\OverlayAdminController;
use App\Http\Controllers\Admin\VideoSurveillanceController;
use App\Http\Controllers\Admin\BookingSettingsController;
use App\Http\Controllers\Admin\AiAssistantSettingsController;
use App\Http\Controllers\Admin\SystemDocsController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\GameRequestAdminController;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\TransactionAdminController;
use App\Http\Controllers\Admin\Store\WarehouseController as StoreWarehouseController;
use App\Http\Controllers\Admin\Store\BuiltPcController as StoreBuiltPcController;
use App\Http\Controllers\Admin\Store\StoreOrderController;
use App\Http\Controllers\Admin\Store\WarrantyController as StoreWarrantyController;
use App\Http\Controllers\Admin\Store\StoreClientController;
use App\Http\Controllers\Admin\Store\EstimateController as StoreEstimateController;
use App\Http\Controllers\Admin\Store\LocationController as StoreLocationController;
use App\Http\Controllers\Admin\Store\AvitoController as StoreAvitoController;
use App\Http\Controllers\StoreAvitoFeedController;
use App\Http\Controllers\StoreAvitoWebhookController;
use App\Http\Controllers\GameRequestController;

/*
|--------------------------------------------------------------------------
| ПУБЛИЧНЫЕ МАРШРУТЫ
|--------------------------------------------------------------------------
*/
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/app.apk', function () {
    $path = storage_path('app/apk/sector0451.apk');
    abort_unless(is_file($path), 404);

    $response = response()->file($path);
    $response->headers->set('Content-Type', 'application/vnd.android.package-archive');
    $response->headers->set('Content-Disposition', 'attachment; filename="sector0451.apk"');
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    $response->headers->set('Cache-Control', 'private, no-transform');

    return $response;
})->name('app.apk');
Route::get('/app.json', function () {
    $path = storage_path('app/apk/sector0451.apk');
    abort_unless(is_file($path), 404);

    return response()->json([
        'version_code' => (int) config('client_app.version_code'),
        'version_name' => (string) config('client_app.version_name'),
        'apk_url' => url('/app.apk'),
        'size' => filesize($path),
    ]);
})->name('app.json');
Route::get('/legal/offer', fn () => Inertia::render('Legal/Offer'))->name('legal.offer');
Route::get('/avito/{token}/feed.xml', [StoreAvitoFeedController::class, 'feed'])->name('store.avito.feed');
Route::get('/avito/{token}/img/{configId}/{sku}/{index}', [StoreAvitoFeedController::class, 'image'])
    ->whereNumber('sku')
    ->whereNumber('index')
    ->name('store.avito.image');
Route::get('/receipt/stub/{transaction}', [\App\Http\Controllers\ReceiptStubController::class, 'show'])
    ->name('receipt.stub');
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
| Гостевой Wi-Fi (walled garden → QR/join → MikroTik grant)
| Хост должен быть в allowlist Hotspot до полного интернета.
|--------------------------------------------------------------------------
*/
Route::get('/wifi/join', [WifiAccessController::class, 'join'])->name('wifi.join');
Route::middleware(['auth:web'])->group(function () {
    Route::post('/api/wifi/authorize', [WifiAccessController::class, 'authorize']);
    Route::post('/api/wifi/revoke', [WifiAccessController::class, 'revoke']);
});
Route::prefix('api/wifi')->group(function () {
    Route::get('/grant-targets', [WifiGrantRelayController::class, 'targets']);
    Route::post('/grant-applied', [WifiGrantRelayController::class, 'applied']);
});

// ЮKassa HTTP-уведомления (без сессии / CSRF)
Route::post('/api/billing/yookassa/webhook', [BillingController::class, 'webhook'])
    ->name('billing.yookassa.webhook');
// Адрес, прописанный в ЛК ЮKassa; вне admin-гарда, иначе уведомление получит редирект на логин.
Route::post('/admin/yookassaStatusSave', [BillingController::class, 'webhook']);

// Embedded widget for Shell (uuid = capability token)
Route::get('/billing/yookassa/widget/{payment}', [BillingController::class, 'widget'])
    ->name('billing.yookassa.widget');
Route::post('/api/billing/yookassa/sync/{payment}', [BillingController::class, 'sync'])
    ->name('billing.yookassa.sync');
Route::get('/api/billing/yookassa/receipt/{payment}', [BillingController::class, 'receiptByPayment'])
    ->name('billing.yookassa.receipt');

/*
|--------------------------------------------------------------------------
| ОБЩИЕ API (ИГРОКИ + АДМИНЫ)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:web,admin'])->prefix('api/shop')->group(function () {
    Route::get('/products', [ShopController::class, 'getProducts']);
    Route::post('/checkout', [ShopController::class, 'checkout']);
    Route::get('/active-orders', [ShopController::class, 'activeOrders']);
    Route::get('/delivery-context', [ShopController::class, 'deliveryContext']);
});

Route::post('/api/terminal/shop/checkout', [ShopController::class, 'terminalCheckout'])
    ->middleware('throttle:20,1');

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
        Route::get('/transfer/targets', [ProfileController::class, 'transferTargets']);
        Route::post('/transfer/preview', [ProfileController::class, 'transferPreview']);
        Route::post('/transfer/confirm', [ProfileController::class, 'transferConfirm']);
        Route::post('/qr/redeem', [\App\Http\Controllers\ShellQrLoginController::class, 'redeem']);
        Route::post('/qr/quote', [\App\Http\Controllers\ShellQrLoginController::class, 'quote']);
        Route::post('/qr/book', [\App\Http\Controllers\ShellQrLoginController::class, 'book']);
    });

    Route::get('/shop', [ShopController::class, 'index'])->name('shop');
    Route::post('/api/bonuses/review', [BonusController::class, 'submitReview']);
    Route::post('/api/promo/apply', [PromoCodeController::class, 'apply']);

    // --- РОУТЫ СИСТЕМЫ БРОНИРОВАНИЯ ---
    Route::post('/api/booking/reserve', [BookingController::class, 'reserve']);
    Route::post('/api/booking/{bookingGroup}/cancel', [BookingController::class, 'cancel']);

    Route::post('/api/billing/topup', [BillingController::class, 'topUp']);
    Route::get('/billing/yookassa/return/{payment}', [BillingController::class, 'returnFromYooKassa'])
        ->name('billing.yookassa.return');
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

Route::middleware(['auth:admin', 'staff.active'])->prefix('admin')->group(function () {

    // Справка — всем ролям
    Route::get('/docs', [SystemDocsController::class, 'index'])->name('admin.docs');

    // Личный кабинет — любой сотрудник (клуб и магазин)
    Route::get('/salary', [StaffPayrollController::class, 'index'])->name('admin.salary');
    Route::post('/salary/withdraw', [StaffPayrollController::class, 'withdraw'])->name('admin.salary.withdraw');
    Route::post('/salary/slots/{slot}/book', [StaffPayrollController::class, 'bookSlot'])->name('admin.salary.slots.book');
    Route::post('/salary/slots/{booking}/cancel', [StaffPayrollController::class, 'cancelSlot'])->name('admin.salary.slots.cancel');
    Route::post('/salary/shift-model', [StaffPayrollController::class, 'setShiftModel'])
        ->middleware('role:owner')
        ->name('admin.salary.shift-model');
    Route::post('/shifts/intern/join', [ShiftController::class, 'internJoin'])->name('admin.shift.intern.join');
    Route::post('/shifts/intern/leave', [ShiftController::class, 'internLeave'])->name('admin.shift.intern.leave');

    // Переключение локации (owner без привязки)
    Route::post('/store/location/switch', [StoreLocationController::class, 'switch'])
        ->middleware('role:owner')
        ->name('admin.store.location.switch');

    // =====================================================================
    // КЛУБ — admin / supervisor / owner (магазинные роли сюда не пускаем)
    // =====================================================================
    Route::middleware(['role:admin,supervisor,owner'])->group(function () {
        Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('admin.dashboard');
        Route::post('/give-bonus', [AdminController::class, 'giveBonus']);
        Route::post('/topup', [AdminController::class, 'topUpBalance']);
        Route::get('/search-user', [AdminController::class, 'searchUser']);
        Route::get('/orders', [AdminController::class, 'orders'])->name('admin.orders');
        Route::post('/orders/status', [AdminController::class, 'updateOrdersStatus']);
        Route::post('/orders/{id}/status', [AdminController::class, 'updateOrderStatus']);
        Route::get('/transactions', [TransactionAdminController::class, 'index'])->name('admin.transactions');
        Route::get('/transactions/{transaction}/print-copy', [TransactionAdminController::class, 'printCopy'])
            ->name('admin.transactions.print-copy');
        Route::get('/shifts/transfer', [ShiftController::class, 'transferPage'])->name('admin.shift.transfer');
        Route::post('/api/shifts/complete', [ShiftController::class, 'completeTransfer']);
        Route::get('/shifts/history', [ShiftController::class, 'history'])->name('admin.shift.history');

        Route::get('/inventory', [AdminController::class, 'inventory'])->name('admin.inventory');
        Route::prefix('api/inventory')->group(function () {
            Route::get('/products', [AdminController::class, 'listInventoryProducts']);
            Route::post('/receive-scan', [AdminController::class, 'receiveScan']);
            Route::post('/update-stock', [AdminController::class, 'updateStock']);
            Route::get('/find-barcode', [AdminController::class, 'findByBarcode']);
            Route::post('/write-off', [AdminController::class, 'writeOffUnit']);
            Route::post('/adjust', [AdminController::class, 'adjustStock']);
        });

        Route::post('/orders/fulfill-scan', [AdminController::class, 'autoFulfillScan']);
        Route::post('/orders/{id}/fulfill-scan', [AdminController::class, 'fulfillOrderScan']);

        Route::get('/incidents', [AdminController::class, 'incidents'])->name('admin.incidents');

        Route::prefix('api')->group(function () {
            Route::get('/pc-statuses', [AdminController::class, 'getPcStatuses']);
            Route::post('/computers/release', [AdminController::class, 'releaseComputer'])
                ->middleware('role:owner');
            Route::get('/check-orders', [AdminController::class, 'checkNewOrders']);
            Route::get('/sos-alerts', [AdminController::class, 'sosAlerts']);
            Route::post('/sos-alerts/{id}/ack', [AdminController::class, 'ackSosAlert']);
            Route::post('/input-alerts/{id}/ack', [AdminController::class, 'ackInputAlert']);
            Route::get('/active-calls', [ChatController::class, 'getActiveCalls']);
            Route::post('/calls/{id}/resolve', [ChatController::class, 'resolveCall']);
        });
    });

    // =====================================================================
    // МАГАЗИН ПРИ КЛУБЕ — store roles + owner (и локация с type store/both)
    // =====================================================================
    Route::middleware(['role:store_manager,assembler,senior_manager,owner', 'store'])
        ->prefix('store')
        ->group(function () {
            Route::get('/warehouse', [StoreWarehouseController::class, 'index'])->name('admin.store.warehouse');
            Route::post('/warehouse', [StoreWarehouseController::class, 'store'])->name('admin.store.warehouse.store');
            Route::put('/warehouse/{storeComponent}', [StoreWarehouseController::class, 'update'])->name('admin.store.warehouse.update');
            Route::delete('/warehouse/{storeComponent}', [StoreWarehouseController::class, 'destroy'])->name('admin.store.warehouse.destroy');
            Route::post('/warehouse/suppliers', [StoreWarehouseController::class, 'storeSupplier'])->name('admin.store.warehouse.suppliers');
            Route::get('/warehouse/suggest', [StoreWarehouseController::class, 'suggest'])->name('admin.store.warehouse.suggest');

            Route::get('/built-pcs', [StoreBuiltPcController::class, 'index'])->name('admin.store.built-pcs');
            Route::post('/built-pcs', [StoreBuiltPcController::class, 'store'])->name('admin.store.built-pcs.store');
            Route::put('/built-pcs/{storeBuiltPc}', [StoreBuiltPcController::class, 'update'])->name('admin.store.built-pcs.update');
            Route::delete('/built-pcs/{storeBuiltPc}', [StoreBuiltPcController::class, 'destroy'])->name('admin.store.built-pcs.destroy');
            Route::get('/built-pcs/{storeBuiltPc}/print-barcode', [StoreWarrantyController::class, 'printBuiltPcBarcode'])->name('admin.store.built-pcs.print-barcode');
            Route::post('/built-pcs/{storeBuiltPc}/print-barcode-pos', [StoreWarrantyController::class, 'printBuiltPcBarcodePos'])->name('admin.store.built-pcs.print-barcode-pos');
            Route::get('/built-pcs/{storeBuiltPc}/print-talon', [StoreWarrantyController::class, 'printBuiltPcTalon'])->name('admin.store.built-pcs.print-talon');

            Route::get('/orders', [StoreOrderController::class, 'index'])->name('admin.store.orders');
            Route::post('/orders', [StoreOrderController::class, 'store'])->name('admin.store.orders.store');
            Route::put('/orders/{storeOrder}', [StoreOrderController::class, 'update'])->name('admin.store.orders.update');
            Route::post('/orders/{storeOrder}/status', [StoreOrderController::class, 'updateStatus'])->name('admin.store.orders.status');
            Route::post('/orders/{storeOrder}/assign', [StoreOrderController::class, 'assign'])->name('admin.store.orders.assign');
            Route::delete('/orders/{storeOrder}/items/{item}', [StoreOrderController::class, 'destroyItem'])->name('admin.store.orders.items.destroy');

            Route::get('/estimates', [StoreEstimateController::class, 'index'])->name('admin.store.estimates');
            Route::post('/estimates', [StoreEstimateController::class, 'store'])->name('admin.store.estimates.store');
            Route::post('/estimates/sync-catalog', [StoreEstimateController::class, 'syncCatalog'])->name('admin.store.estimates.sync-catalog');
            Route::post('/estimates/sync-prices', [StoreEstimateController::class, 'syncPrices'])->name('admin.store.estimates.sync-prices');
            Route::get('/estimates/catalog-search', [StoreEstimateController::class, 'searchCatalog'])->name('admin.store.estimates.catalog-search');
            Route::get('/estimates/catalog-images/{sku}', [StoreEstimateController::class, 'catalogImages'])->name('admin.store.estimates.catalog-images')->whereNumber('sku');
            Route::get('/estimates/catalog-image/{sku}', [StoreEstimateController::class, 'catalogImage'])->name('admin.store.estimates.catalog-image')->whereNumber('sku');
            Route::post('/estimates/catalog-prices', [StoreEstimateController::class, 'catalogPrices'])->name('admin.store.estimates.catalog-prices');
            Route::get('/estimates/categories', [StoreEstimateController::class, 'categories'])->name('admin.store.estimates.categories');
            Route::put('/estimates/{storeEstimate}', [StoreEstimateController::class, 'update'])->name('admin.store.estimates.update');
            Route::delete('/estimates/{storeEstimate}', [StoreEstimateController::class, 'destroy'])->name('admin.store.estimates.destroy');
            Route::get('/estimates/{storeEstimate}/pdf', [StoreEstimateController::class, 'printPdf'])->name('admin.store.estimates.pdf');
            Route::post('/estimates/{storeEstimate}/status', [StoreEstimateController::class, 'updateStatus'])->name('admin.store.estimates.status');
            Route::post('/estimates/{storeEstimate}/check-supplier', [StoreEstimateController::class, 'checkSupplier'])->name('admin.store.estimates.check-supplier');
            Route::post('/estimates/{storeEstimate}/order-missing', [StoreEstimateController::class, 'orderMissing'])->name('admin.store.estimates.order-missing');
            Route::post('/estimates/{storeEstimate}/convert', [StoreEstimateController::class, 'convert'])->name('admin.store.estimates.convert');
            Route::post('/estimate-items/{storeEstimateItem}/link-stock', [StoreEstimateController::class, 'linkStock'])->name('admin.store.estimate-items.link-stock');
            Route::post('/estimate-items/{storeEstimateItem}/unlink-stock', [StoreEstimateController::class, 'unlinkStock'])->name('admin.store.estimate-items.unlink-stock');
            Route::post('/purchases/{storePurchase}/receive', [StoreEstimateController::class, 'receivePurchase'])->name('admin.store.purchases.receive');

            Route::get('/warranty', [StoreWarrantyController::class, 'index'])->name('admin.store.warranty');
            Route::post('/warranty', [StoreWarrantyController::class, 'store'])->name('admin.store.warranty.store');
            Route::post('/warranty/{storeWarranty}', [StoreWarrantyController::class, 'update'])->name('admin.store.warranty.update');
            Route::post('/warranty/{storeWarranty}/send-to-repair', [StoreWarrantyController::class, 'sendToRepair'])->name('admin.store.warranty.send-to-repair');
            Route::post('/warranty/{storeWarranty}/return-from-repair', [StoreWarrantyController::class, 'returnFromRepair'])->name('admin.store.warranty.return-from-repair');
            Route::post('/warranty/{storeWarranty}/replace-component', [StoreWarrantyController::class, 'replaceComponent'])->name('admin.store.warranty.replace-component');
            Route::get('/warranty/{storeWarranty}/print-barcode', [StoreWarrantyController::class, 'printBarcode'])->name('admin.store.warranty.print-barcode');
            Route::post('/warranty/{storeWarranty}/print-barcode-pos', [StoreWarrantyController::class, 'printBarcodePos'])->name('admin.store.warranty.print-barcode-pos');
            Route::get('/warranty/{storeWarranty}/print-talon', [StoreWarrantyController::class, 'printTalon'])->name('admin.store.warranty.print-talon');

            Route::get('/clients', [StoreClientController::class, 'index'])->name('admin.store.clients');
            Route::post('/clients', [StoreClientController::class, 'store'])->name('admin.store.clients.store');
            Route::put('/clients/{storeClient}', [StoreClientController::class, 'update'])->name('admin.store.clients.update');
            Route::delete('/clients/{storeClient}', [StoreClientController::class, 'destroy'])->name('admin.store.clients.destroy');

            Route::get('/avito', [StoreAvitoController::class, 'index'])->name('admin.store.avito');
            Route::put('/avito/settings', [StoreAvitoController::class, 'updateSettings'])->name('admin.store.avito.settings');
            Route::post('/avito/generate', [StoreAvitoController::class, 'generate'])->name('admin.store.avito.generate');
            Route::post('/avito/dicts', [StoreAvitoController::class, 'syncDicts'])->name('admin.store.avito.dicts');
            Route::post('/avito/configs', [StoreAvitoController::class, 'storeConfig'])->name('admin.store.avito.configs.store');
            Route::post('/avito/configs/{storeAvitoConfig}', [StoreAvitoController::class, 'updateConfig'])->name('admin.store.avito.configs.update');
            Route::delete('/avito/configs/{storeAvitoConfig}', [StoreAvitoController::class, 'destroyConfig'])->name('admin.store.avito.configs.destroy');
            Route::post('/avito/webhook', [StoreAvitoController::class, 'connectWebhook'])->name('admin.store.avito.webhook.connect');
            Route::post('/avito/ads/{storeAvitoAd}', [StoreAvitoController::class, 'updateAd'])->name('admin.store.avito.ads.update');
            Route::post('/avito/chats/send', [StoreAvitoController::class, 'sendMessage'])->name('admin.store.avito.chats.send');
            Route::post('/avito/chats/bom', [StoreAvitoController::class, 'sendBom'])->name('admin.store.avito.chats.bom');
            Route::post('/avito/chats/{storeAvitoChat}', [StoreAvitoController::class, 'markChat'])->name('admin.store.avito.chats.mark');
        });

    // УРОВЕНЬ: SUPERVISOR+
    Route::middleware(['role:supervisor,owner'])->group(function () {
        Route::prefix('api/inventory')->group(function () {
            Route::post('/save', [AdminController::class, 'saveProduct']);
            Route::delete('/delete/{id}', [AdminController::class, 'deleteProduct']);
        });

        Route::get('/suppliers', [SupplierController::class, 'index'])->name('admin.suppliers');
        Route::post('/suppliers', [SupplierController::class, 'store']);
        Route::put('/suppliers/{supplier}', [SupplierController::class, 'update']);
        Route::post('/suppliers/invoices', [SupplierController::class, 'storeInvoice']);
        Route::post('/suppliers/invoices/{invoice}/pay', [SupplierController::class, 'payInvoice']);

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

        Route::get('/quick-apps', [QuickAppController::class, 'index'])->name('admin.quick-apps');
        Route::post('/quick-apps', [QuickAppController::class, 'store']);
        Route::delete('/quick-apps/{quickApp}', [QuickAppController::class, 'destroy']);

        // ВИДЕОНАБЛЮДЕНИЕ · МЕТКИ НА ТАЙМЛАЙНЕ
        Route::get('/video-surveillance', [VideoSurveillanceController::class, 'index'])->name('admin.video-surveillance');
        Route::put('/video-surveillance', [VideoSurveillanceController::class, 'updateSettings']);
        Route::post('/video-surveillance/test', [VideoSurveillanceController::class, 'test']);
        Route::post('/video-surveillance/events', [VideoSurveillanceController::class, 'storeEvent']);
        Route::put('/video-surveillance/events/{event}', [VideoSurveillanceController::class, 'updateEvent']);
        Route::delete('/video-surveillance/events/{event}', [VideoSurveillanceController::class, 'destroyEvent']);

        Route::get('/booking-settings', [BookingSettingsController::class, 'index'])->name('admin.booking-settings');
        Route::post('/booking-settings', [BookingSettingsController::class, 'update']);

        Route::get('/ai-assistant', [AiAssistantSettingsController::class, 'index'])->name('admin.ai-assistant');
        Route::post('/ai-assistant', [AiAssistantSettingsController::class, 'update']);
        Route::post('/ai-assistant/reset-prompts', [AiAssistantSettingsController::class, 'resetPrompts']);
        Route::post('/ai-assistant/test-llm', [AiAssistantSettingsController::class, 'testLlm']);
        Route::post('/ai-assistant/test-question', [AiAssistantSettingsController::class, 'testQuestion']);
        Route::post('/ai-assistant/test-tts', [AiAssistantSettingsController::class, 'testTts']);

        Route::prefix('api')->group(function () {
            Route::post('/incidents/{id}/resolve', [AdminController::class, 'resolveIncident']);
            Route::get('/overlays', [OverlayAdminController::class, 'getOverlays']);
            Route::put('/overlays/{id}', [OverlayAdminController::class, 'updateOverlay']);
            Route::post('/upload-image', [OverlayAdminController::class, 'uploadImage']);
            Route::post('/upload-video', [OverlayAdminController::class, 'uploadVideo']);
            Route::post('/fans/{fan}/force-off', [FanAdminController::class, 'forceOff']);
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

        Route::get('/fans', [FanAdminController::class, 'index'])->name('admin.fans');
        Route::post('/fans/boards', [FanAdminController::class, 'storeBoard']);
        Route::put('/fans/boards/{board}', [FanAdminController::class, 'updateBoard']);
        Route::delete('/fans/boards/{board}', [FanAdminController::class, 'destroyBoard']);
        Route::post('/fans/shared', [FanAdminController::class, 'storeSharedFan']);
        Route::put('/fans/shared/{sharedFan}/maps', [FanAdminController::class, 'updateSharedMaps']);
        Route::post('/fans/shared/{sharedFan}/link', [FanAdminController::class, 'linkSharedFan']);
        Route::post('/fans/shared/{sharedFan}/unlink', [FanAdminController::class, 'unlinkSharedFan']);
        Route::delete('/fans/shared/{sharedFan}', [FanAdminController::class, 'destroySharedFan']);
        Route::post('/fans', [FanAdminController::class, 'storeFan']);
        Route::put('/fans/{fan}', [FanAdminController::class, 'updateFan']);
        Route::delete('/fans/{fan}', [FanAdminController::class, 'destroyFan']);

        Route::get('/lights', [LightAdminController::class, 'index'])->name('admin.lights');
        Route::post('/lights/nodes', [LightAdminController::class, 'storeNode']);
        Route::put('/lights/nodes/{node}', [LightAdminController::class, 'updateNode']);
        Route::delete('/lights/nodes/{node}', [LightAdminController::class, 'destroyNode']);
        Route::post('/lights', [LightAdminController::class, 'storeLight']);
        Route::put('/lights/{light}', [LightAdminController::class, 'updateLight']);
        Route::delete('/lights/{light}', [LightAdminController::class, 'destroyLight']);
    });

    // УРОВЕНЬ: OWNER
    Route::middleware(['role:owner'])->group(function () {
        Route::get('/taxes', [TaxController::class, 'index'])->name('admin.taxes.index');
        Route::get('/staff', [StaffController::class, 'index'])->name('admin.staff.index');
        Route::post('/staff/{admin}/fines', [StaffController::class, 'storeFine'])->name('admin.staff.fines.store');
        Route::post('/staff/{admin}/role', [StaffController::class, 'updateRole'])->name('admin.staff.role.update');

        Route::get('/store/locations', [StoreLocationController::class, 'index'])->name('admin.store.locations');
        Route::post('/store/locations', [StoreLocationController::class, 'store'])->name('admin.store.locations.store');
        Route::put('/store/locations/{location}', [StoreLocationController::class, 'update'])->name('admin.store.locations.update');
    });

    Route::post('/logout', [AdminLoginController::class, 'logout'])->name('admin.logout');
});

Route::prefix('api/store')->group(function () {
    Route::post('/build-verify', \App\Http\Controllers\Api\StoreBuildVerifyController::class)
        ->name('api.store.build-verify');
    Route::post('/avito/webhook', StoreAvitoWebhookController::class)
        ->name('api.store.avito.webhook');
});

/*
|--------------------------------------------------------------------------
| API ДЛЯ QML-ШЕЛЛА (Терминалы клуба)
|--------------------------------------------------------------------------
*/
/*
|--------------------------------------------------------------------------
| WOL-релей (MikroTik pull): роутер сам забирает MAC, облако пакет не шлёт
|--------------------------------------------------------------------------
*/
Route::prefix('api/power')->group(function () {
    Route::get('/wol-targets', [WolRelayController::class, 'targets']);
    Route::post('/wol-sent', [WolRelayController::class, 'sent']);
    Route::get('/isolate-targets', [ShellIsolateRelayController::class, 'targets']);
    Route::post('/isolate-applied', [ShellIsolateRelayController::class, 'applied']);
});

Route::prefix('api/fans')->group(function () {
    Route::get('/shared-targets', [SharedFanRelayController::class, 'targets']);
    Route::post('/shared-applied', [SharedFanRelayController::class, 'applied']);
});

Route::prefix('api/kitchen')->group(function () {
    Route::get('/print-targets', [KitchenPrintRelayController::class, 'targets']);
    Route::post('/print-applied', [KitchenPrintRelayController::class, 'applied']);
});

Route::prefix('api/video')->group(function () {
    Route::get('/marker-targets', [VideoMarkerRelayController::class, 'targets']);
    Route::post('/marker-applied', [VideoMarkerRelayController::class, 'applied']);
});

/*
|--------------------------------------------------------------------------
| API ДЛЯ QML-ШЕЛЛА (Терминалы клуба)
|--------------------------------------------------------------------------
*/
Route::prefix('api/shell')->group(function () {
    // --- ПРОВЕРКА И АВТОМАТИЧЕСКАЯ РЕГИСТРАЦИЯ ОБОРУДОВАНИЯ ПО СЕРИЙНИКУ (HWID) ---
    Route::post('/check', [ShellApiController::class, 'checkTerminalBooking']); // Стартовый роут проверки HWID
    Route::post('/register-terminal', [ShellApiController::class, 'registerTerminal']); // Кнопка "Привязать ПК"
    Route::post('/power/heartbeat', [ShellApiController::class, 'powerHeartbeat']);
    Route::post('/power/offline', [ShellApiController::class, 'powerOffline']);
    Route::post('/ui-state', [ShellApiController::class, 'reportUiState']);

    Route::get('/overlays', [ShellApiController::class, 'getActiveOverlays']);
    Route::post('/login', [ShellApiController::class, 'login']);
    Route::post('/qr/challenge', [ShellApiController::class, 'qrChallenge']);
    Route::get('/qr/status', [ShellApiController::class, 'qrStatus']);
    Route::get('/balance', [ShellApiController::class, 'getBalance']);
    Route::get('/transfer/targets', [ShellApiController::class, 'transferTargets']);
    Route::post('/transfer/preview', [ShellApiController::class, 'transferPreview']);
    Route::post('/transfer/confirm', [ShellApiController::class, 'transferConfirm']);
    Route::get('/session/extend/options', [ShellApiController::class, 'extendOptions']);
    Route::post('/session/extend', [ShellApiController::class, 'extendSession']);
    Route::post('/billing/topup', [ShellApiController::class, 'topUp']);
    Route::get('/games', [ShellApiController::class, 'getGames']);
    Route::get('/quick-apps', [ShellApiController::class, 'getQuickApps']);
    Route::get('/games/tops', [ShellApiController::class, 'getGameTops']);
    Route::post('/games/record-launch', [ShellApiController::class, 'recordGameLaunch']);

    // --- МАГАЗИН И БАР ДЛЯ ТЕРМИНАЛА ---
    Route::get('/store/products', [ShellApiController::class, 'getProducts']);
    Route::post('/store/checkout', [ShellApiController::class, 'checkout']);
    Route::get('/store/order-status', [ShellApiController::class, 'getOrderStatus']);
    Route::post('/store/release-scheduled', [ShellApiController::class, 'releaseScheduledOrder']);
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

    // --- Вентиляция комнаты (Space): Shell только события ---
    Route::post('/thermal', [ShellApiController::class, 'reportThermal']);
    Route::post('/fan', [ShellApiController::class, 'controlFan']);
    Route::post('/fan/applied', [ShellApiController::class, 'acknowledgeFanApplied']);
    Route::get('/fan', [ShellApiController::class, 'getFanState']);
    Route::get('/fan/discover', [ShellApiController::class, 'discoverFan']);
    Route::post('/fan/bind', [ShellApiController::class, 'bindFan']);
    Route::post('/fan/unbind', [ShellApiController::class, 'unbindFan']);

    Route::post('/light', [ShellApiController::class, 'controlLight']);
    Route::post('/light/applied', [ShellApiController::class, 'acknowledgeLightApplied']);
    Route::get('/light', [ShellApiController::class, 'getLightState']);

    // --- F1 AI-компаньон (голос → ответ в наушники) ---
    Route::post('/ai-assistant', [ShellApiController::class, 'aiAssistant']);
    Route::get('/ai-voices', [ShellApiController::class, 'aiVoices']);
    Route::post('/ai-voice', [ShellApiController::class, 'setAiVoice']);

    // --- Голосовое приветствие после login (колонки) ---
    Route::post('/voice-greeting', [ShellApiController::class, 'voiceGreeting']);

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
