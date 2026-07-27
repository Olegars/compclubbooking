<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Order;
use App\Models\ComputerSosAlert;
use App\Models\ComputerInputAlert;
use App\Support\AdminAlerts;
// Если у тебя есть модель BonusLog, раскомментируй:
// use App\Models\BonusLog;

class AdminController extends Controller
{
    // ... твои старые методы (index, orders и т.д.) оставляем ...

    // ==========================================
    // 1. ДАШБОАРД И КОМПЕНСАЦИИ
    // ==========================================
    public function dashboard()
    {
        // Получаем ID первого клуба (или текущего активного)
        $clubId = DB::table('clubs')->first()->id ?? 1;

        // Загружаем реальные ПК из базы
        $computers = DB::table('computers')
            ->where('club_id', $clubId)
            ->select('id', 'name', 'status') // Предполагаем, что колонка status есть (available/busy)
            ->orderBy('name', 'asc')
            ->get();

        return Inertia::render('Admin/Dashboard', [
            'computers' => $computers,
            'stats' => [
                'TOTAL_REVENUE' => DB::table('orders')->where('status', 'delivered')->sum('price') ?? 0,
                'ACTIVE_SESSIONS' => $computers->where('status', 'busy')->count(),
                'NEW_USERS_TODAY' => DB::table('users')->whereDate('created_at', today())->count()
            ]
        ]);
    }

    public function searchUser(Request $request)
    {
        $request->validate(['phone' => 'required|string']);

        $user = User::with('wallet')->where('phone', 'like', '%' . $request->phone . '%')->first();

        if (!$user) {
            return response()->json(['message' => 'Гость не найден'], 404);
        }

        $balance = $user->availableBalance();

        return response()->json(array_merge($user->toArray(), [
            'balance' => $balance,
            'total_balance' => $balance,
        ]));
    }

    public function giveBonus(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'minutes' => 'required|integer|min:1',
            'reason'  => 'required|string|max:255'
        ]);

        // Логируем выдачу бонуса в БД (таблица, которую мы создали в миграции)
        DB::table('bonus_logs')->insert([
            'user_id'    => $request->user_id,
            'admin_id'   => Auth::guard('admin')->id(), // ID текущего оператора
            'minutes'    => $request->minutes,
            'reason'     => $request->reason,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // *Здесь позже будет API-запрос к Gizmo для фактического начисления времени*

        return response()->json(['message' => 'Бонус успешно начислен и залогирован']);
    }

    /**
     * Касса: ручное пополнение deposit_balance (без эквайринга).
     */
    public function topUpBalance(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'amount' => 'required|numeric|min:100',
            'reason' => 'nullable|string|max:255',
        ]);

        $user = User::findOrFail($request->user_id);
        $amount = (float) $request->amount;

        $newBalance = DB::transaction(function () use ($user, $amount, $request) {
            $user->syncBalanceToWallet();
            $wallet = $user->wallet()->firstOrCreate(['user_id' => $user->id]);
            $balance = $wallet->creditSpendable($amount);

            DB::table('transactions')->insert([
                'user_id' => $user->id,
                'amount' => $amount,
                'type' => 'deposit',
                'source' => 'admin_cash',
                'description' => $request->reason ?: 'Пополнение кассой REACTOR',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $balance;
        });

        return response()->json([
            'message' => 'Баланс пополнен',
            'balance' => $newBalance,
            'deposit_balance' => $newBalance,
            'new_balance' => $newBalance,
        ]);
    }

    // ==========================================
    // 2. СКЛАД МАРКЕТА (ИНВЕНТАРЬ)
    // ==========================================
    public function inventory()
    {
        return Inertia::render('Admin/Inventory');
    }

    public function saveProduct(Request $request)
    {
        // 1. Валидируем данные. image здесь — это загружаемый файл изображения
        $request->validate([
            'id'       => 'nullable|integer',
            'name'     => 'required|string|max:255',
            'category' => 'required|string',
            'price'    => 'required|numeric',
            'stock'    => 'nullable|integer|min:0',
            'image'    => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048' // Проверка файла
        ]);

        $data = [
            'name'     => $request->name,
            'category' => $request->category,
            'price'    => $request->price,
            'stock'    => $request->stock ?? 0,
            'barcode'  => $request->barcode ?? null,
        ];

        // 2. ОБРАБОТКА И ЗАГРУЗКА ФАЙЛА КАРТИНКИ
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $filename = time() . '_' . $file->getClientOriginalName();

            // 1. Физический путь для сохранения на сервере
            $targetPath = public_path('images/shop');

            // Создаем папку, если её нет, и даем права 0755
            if (!file_exists($targetPath)) {
                mkdir($targetPath, 0755, true);
            }

            // 2. Перемещаем файл
            $file->move($targetPath, $filename);

            // 3. Сохраняем в базу БЕЗ ведущего слэша (чистый относительный путь)
            $data['image'] = 'images/shop/' . $filename;
        }

        // 3. СОХРАНЕНИЕ В БАЗУ ДАННЫХ
        if ($request->id) {
            // Если товар редактируется
            DB::table('products')->where('id', $request->id)->update($data);
        } else {
            // Если создается новый товар и картинка не была загружена — ставим пустую строку вместо null
            if (!isset($data['image'])) {
                $data['image'] = '';
            }
            DB::table('products')->insert($data);
        }

        return response()->json(['message' => 'Товар успешно сохранен']);
    }

    public function deleteProduct($id)
    {
        DB::table('products')->where('id', $id)->delete();
        return response()->json(['message' => 'Товар удален']);
    }

    // ==========================================
    // 3. РЕЕСТР БОНУСОВ
    // ==========================================
    public function bonusLogs()
    {
        // Получаем логи с привязкой к юзеру и админу
        $logs = DB::table('bonus_logs')
            ->join('users', 'bonus_logs.user_id', '=', 'users.id')
            ->leftJoin('admins', 'bonus_logs.admin_id', '=', 'admins.id') // Замени 'admins' на 'users', если у тебя админы в таблице users
            ->select(
                'bonus_logs.*',
                'users.name as user_name',
                'users.phone as user_phone',
                'admins.name as admin_name'
            )
            ->orderByDesc('bonus_logs.created_at')
            ->get()
            ->map(function ($log) {
                // Форматируем под Vue структуру
                return [
                    'id'         => $log->id,
                    'minutes'    => $log->minutes,
                    'reason'     => $log->reason,
                    'created_at' => $log->created_at,
                    'user'       => ['name' => $log->user_name, 'phone' => $log->user_phone],
                    'admin'      => ['name' => $log->admin_name]
                ];
            });

        $todayMinutes = DB::table('bonus_logs')->whereDate('created_at', today())->sum('minutes');
        $monthMinutes = DB::table('bonus_logs')->whereMonth('created_at', now()->month)->sum('minutes');

        return Inertia::render('Admin/BonusLogs', [
            'logs'  => $logs,
            'stats' => [
                'today_minutes' => (int) $todayMinutes,
                'month_minutes' => (int) $monthMinutes
            ]
        ]);
    }
    // ==========================================
    // 4. ОЧЕРЕДЬ ЗАКАЗОВ (REACTOR MARKET)
    // ==========================================
    public function orders()
    {
        // Активная очередь: новые (pending) и в работе (cooking)
        $orders = DB::table('orders')
            ->join('users', 'orders.user_id', '=', 'users.id')
            ->select(
                'orders.*',
                'users.name as user_name',
                'users.phone as user_phone'
            )
            ->whereIn('orders.status', ['pending', 'cooking'])
            ->orderBy('orders.created_at', 'asc')
            ->get()
            ->map(function ($order) {
                $labels = [
                    'pending' => 'Принят',
                    'cooking' => 'В работе',
                    'delivered' => 'Выполнен',
                    'cancelled' => 'Отменён',
                ];
                $items = Order::normalizeItems(
                    $order->items ?? null,
                    $order->product_name ?? null,
                    (float) ($order->price ?? 0)
                );
                return [
                    'id' => $order->id,
                    'product_name' => $order->product_name,
                    'items' => $items,
                    'price' => (float) ($order->price ?? 0),
                    'pc_name' => $order->pc_name,
                    'status' => $order->status,
                    'status_label' => $labels[$order->status] ?? $order->status,
                    'user' => [
                        'name' => $order->user_name,
                        'phone' => $order->user_phone,
                    ],
                ];
            });

        return Inertia::render('Admin/Orders', [
            'orders' => $orders
        ]);
    }

    public function updateOrderStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:pending,cooking,delivered,cancelled'
        ]);

        DB::table('orders')->where('id', $id)->update([
            'status' => $request->status,
            'updated_at' => now()
        ]);

        return back();
    }
    public function getPcStatuses()
    {
        // Возвращаем только ID и статус всех ПК клуба
        $statuses = DB::table('computers')
            ->select('id', 'status')
            ->get();

        return response()->json($statuses);
    }
    public function checkNewOrders()
    {
        // Считаем только те, что еще не приняты (статус pending)
        $count = DB::table('orders')->where('status', 'pending')->count();

        return response()->json(['count' => $count]);
    }
    // resources/app/Http/Controllers/Admin/AdminController.php

    public function updateStock(Request $request)
    {
        // Важно: integer позволяет принимать отрицательные числа (например, -1)
        $request->validate([
            'id' => 'required|exists:products,id',
            'amount' => 'required|integer',
        ]);

        $product = DB::table('products')->where('id', $request->id);
        $current = $product->first();

        // Проверка на уход в минус
        if (($current->stock + $request->amount) < 0) {
            return response()->json(['message' => 'Недостаточно товара на складе'], 422);
        }

        // Используем increment, он отлично понимает отрицательные числа (добавляет -1)
        $product->increment('stock', (int)$request->amount);

        return response()->json([
            'status' => 'success',
            'new_stock' => $current->stock + $request->amount
        ]);
    }
    public function findByBarcode(Request $request)
    {
        $request->validate(['code' => 'required|string']);

        // Ищем товар по штрих-коду
        $product = \App\Models\Product::where('barcode', $request->code)->first();

        if (!$product) {
            return response()->json(['message' => 'Объект не опознан. Код отсутствует в базе.'], 404);
        }

        return response()->json($product);
    }
    // ==========================================
    // 5. SOS И HID-СИГНАЛЫ С ТЕРМИНАЛОВ (QML SHELL)
    // ==========================================

    /**
     * Активные (необработанные) SOS-вызовы и аномалии периферии для дашбоарда.
     */
    public function sosAlerts()
    {
        $sos = ComputerSosAlert::with('computer:id,name')
            ->whereNull('resolved_at')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(function (ComputerSosAlert $alert) {
                return [
                    'id' => $alert->id,
                    'computer_id' => $alert->computer_id,
                    'pc_name' => $this->pcName($alert->computer?->name, $alert->computer_id),
                    'booking_id' => $alert->booking_id,
                    'reason_code' => $alert->reason_code,
                    'reason' => $alert->reason_label ?: $this->sosReasonLabel($alert->reason_code),
                    'created_at' => optional($alert->created_at)->toIso8601String(),
                    'time' => optional($alert->created_at)->format('H:i'),
                    'waiting_minutes' => $this->minutesAgo($alert->created_at),
                ];
            })
            ->values();

        $input = ComputerInputAlert::with('computer:id,name')
            ->whereNull('resolved_at')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(function (ComputerInputAlert $alert) {
                return [
                    'id' => $alert->id,
                    'computer_id' => $alert->computer_id,
                    'pc_name' => $this->pcName($alert->computer?->name, $alert->computer_id),
                    'type' => $alert->type,
                    'type_label' => $this->inputAlertLabel($alert->type),
                    'severity' => $this->normalizeSeverity($alert->severity),
                    'details' => $this->inputAlertDetails($alert->payload),
                    'created_at' => optional($alert->created_at)->toIso8601String(),
                    'time' => optional($alert->created_at)->format('H:i'),
                ];
            })
            ->values();

        return response()->json([
            'sos' => $sos,
            'input' => $input,
            'counts' => AdminAlerts::counts(),
        ]);
    }

    /**
     * Оператор принял SOS-вызов.
     */
    public function ackSosAlert($id)
    {
        if (! $this->resolveSosAlert((int) $id)) {
            return response()->json(['message' => 'Сигнал не найден'], 404);
        }

        return response()->json(['status' => 'resolved', 'counts' => AdminAlerts::counts()]);
    }

    /**
     * Оператор принял сигнал о подмене/отключении периферии.
     */
    public function ackInputAlert($id)
    {
        if (! $this->resolveInputAlert((int) $id)) {
            return response()->json(['message' => 'Сигнал не найден'], 404);
        }

        return response()->json(['status' => 'resolved', 'counts' => AdminAlerts::counts()]);
    }

    // ==========================================
    // 6. РЕЕСТР ИНЦИДЕНТОВ
    // ==========================================
    public function incidents()
    {
        // Страховка на случай, если планировщик (reactor:check-quality) не запущен на машине клуба
        $this->syncLateOrderIncidents();

        $manual = DB::table('incidents')
            ->whereNull('resolved_at')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(function ($row) {
                $createdAt = $row->created_at ? Carbon::parse($row->created_at) : now();

                return [
                    'id' => 'inc-'.$row->id,
                    'source' => 'incident',
                    'type' => $row->type,
                    'type_label' => $this->incidentTypeLabel($row->type),
                    'severity' => $this->normalizeSeverity($row->severity),
                    'description' => $row->description,
                    'order_id' => $row->order_id,
                    'pc_name' => null,
                    'created_at' => $createdAt->toIso8601String(),
                    'sort_ts' => $createdAt->getTimestamp(),
                    'resolved' => false,
                ];
            });

        $sos = ComputerSosAlert::with('computer:id,name')
            ->whereNull('resolved_at')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(function (ComputerSosAlert $alert) {
                $createdAt = $alert->created_at ?: now();
                $pcName = $this->pcName($alert->computer?->name, $alert->computer_id);
                $reason = $alert->reason_label ?: $this->sosReasonLabel($alert->reason_code);

                return [
                    'id' => 'sos-'.$alert->id,
                    'source' => 'sos',
                    'type' => 'sos',
                    'type_label' => 'SOS с терминала',
                    'severity' => 'high',
                    'description' => "SOS {$pcName}: {$reason}",
                    'order_id' => null,
                    'pc_name' => $pcName,
                    'created_at' => $createdAt->toIso8601String(),
                    'sort_ts' => $createdAt->getTimestamp(),
                    'resolved' => false,
                ];
            });

        $input = ComputerInputAlert::with('computer:id,name')
            ->whereNull('resolved_at')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(function (ComputerInputAlert $alert) {
                $createdAt = $alert->created_at ?: now();
                $pcName = $this->pcName($alert->computer?->name, $alert->computer_id);
                $label = $this->inputAlertLabel($alert->type);
                $details = $this->inputAlertDetails($alert->payload);

                return [
                    'id' => 'hid-'.$alert->id,
                    'source' => 'input',
                    'type' => $alert->type,
                    'type_label' => $label,
                    'severity' => $this->normalizeSeverity($alert->severity),
                    'description' => trim("{$label} на {$pcName}. {$details}"),
                    'order_id' => null,
                    'pc_name' => $pcName,
                    'created_at' => $createdAt->toIso8601String(),
                    'sort_ts' => $createdAt->getTimestamp(),
                    'resolved' => false,
                ];
            });

        $incidents = $manual
            ->concat($sos)
            ->concat($input)
            ->sortByDesc('sort_ts')
            ->values();

        return Inertia::render('Admin/Incidents', [
            'incidents' => $incidents,
        ]);
    }

    /**
     * Закрытие записи реестра. ID приходит с префиксом источника: inc-12 / sos-3 / hid-7.
     */
    public function resolveIncident($id)
    {
        [$source, $rawId] = $this->parseIncidentId((string) $id);

        $resolved = match ($source) {
            'sos' => $this->resolveSosAlert($rawId),
            'hid' => $this->resolveInputAlert($rawId),
            default => $this->resolveManualIncident($rawId),
        };

        if (! $resolved) {
            return response()->json(['message' => 'Запись не найдена'], 404);
        }

        return response()->json(['status' => 'resolved', 'counts' => AdminAlerts::counts()]);
    }

    // ==========================================
    // ХЕЛПЕРЫ АЛЕРТОВ / ИНЦИДЕНТОВ
    // ==========================================

    private function resolveSosAlert(int $id): bool
    {
        $alert = ComputerSosAlert::with('computer:id,name')->find($id);
        if (! $alert) {
            return false;
        }

        if (! $alert->resolved_at) {
            $alert->resolved_at = now();
            $alert->save();

            // SOS дублируется в общий канал вызовов — гасим и его, чтобы не звонить дважды
            $pcName = $this->pcName($alert->computer?->name, $alert->computer_id);
            DB::table('admin_calls')
                ->where('status', 'pending')
                ->where('pc_name', $pcName)
                ->where('message', 'SOS: '.$alert->reason_label)
                ->update(['status' => 'resolved', 'updated_at' => now()]);

            Log::info('[SOS-ACK]', [
                'alert_id' => $alert->id,
                'admin_id' => Auth::guard('admin')->id(),
            ]);
        }

        return true;
    }

    private function resolveInputAlert(int $id): bool
    {
        $alert = ComputerInputAlert::find($id);
        if (! $alert) {
            return false;
        }

        if (! $alert->resolved_at) {
            $alert->resolved_at = now();
            $alert->save();

            Log::info('[HID-ACK]', [
                'alert_id' => $alert->id,
                'admin_id' => Auth::guard('admin')->id(),
            ]);
        }

        return true;
    }

    private function resolveManualIncident(int $id): bool
    {
        $incident = DB::table('incidents')->where('id', $id)->first();
        if (! $incident) {
            return false;
        }

        DB::table('incidents')->where('id', $id)->update([
            'resolved_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info('[INCIDENT-RESOLVED]', [
            'incident_id' => $id,
            'type' => $incident->type,
            'admin_id' => Auth::guard('admin')->id(),
        ]);

        return true;
    }

    private function parseIncidentId(string $id): array
    {
        if (preg_match('/^(inc|sos|hid)-(\d+)$/', $id, $matches)) {
            return [$matches[1], (int) $matches[2]];
        }

        return ['inc', (int) $id];
    }

    /**
     * Фиксация просроченных заказов (та же логика, что в команде reactor:check-quality).
     */
    private function syncLateOrderIncidents(): void
    {
        try {
            $lateOrders = DB::table('orders')
                ->where('status', 'pending')
                ->where('created_at', '<', now()->subMinutes(5))
                ->get(['id', 'product_name']);

            foreach ($lateOrders as $order) {
                $exists = DB::table('incidents')
                    ->where('type', 'late_order')
                    ->where('order_id', $order->id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('incidents')->insert([
                    'type' => 'late_order',
                    'order_id' => $order->id,
                    'severity' => 'high',
                    'description' => "КРИТИЧЕСКАЯ ЗАДЕРЖКА: Заказ #{$order->id} ({$order->product_name}) не обработан за 5+ минут.",
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('syncLateOrderIncidents: '.$e->getMessage());
        }
    }

    private function pcName(?string $name, ?int $computerId): string
    {
        return $name ?: ('PC-'.($computerId ?? 0));
    }

    private function sosReasonLabel(?string $code): string
    {
        return match ($code) {
            ComputerSosAlert::REASON_PERIPHERALS => 'Проблема с периферией',
            ComputerSosAlert::REASON_AUTH_HELP => 'Помощь с авторизацией',
            ComputerSosAlert::REASON_OTHER => 'Другая причина',
            default => 'Вызов оператора',
        };
    }

    private function inputAlertLabel(?string $type): string
    {
        return match ($type) {
            ComputerInputAlert::TYPE_DEVICE_CHANGED => 'Подмена периферии',
            ComputerInputAlert::TYPE_DISCONNECTED => 'Периферия отключена',
            ComputerInputAlert::TYPE_UNSTABLE => 'Нестабильная периферия',
            default => 'Аномалия периферии',
        };
    }

    private function inputAlertDetails($payload): string
    {
        if (! is_array($payload)) {
            return '';
        }

        $current = is_array($payload['current'] ?? null) ? $payload['current'] : [];
        $mice = is_array($current['mice'] ?? null) ? count($current['mice']) : 0;
        $keyboards = is_array($current['keyboards'] ?? null) ? count($current['keyboards']) : 0;
        $burst = (int) ($payload['burst_count'] ?? 0);

        return "Мышей: {$mice}, клавиатур: {$keyboards}, срабатываний за минуту: {$burst}";
    }

    private function incidentTypeLabel(?string $type): string
    {
        return match ($type) {
            'late_order' => 'Задержка сервиса',
            'inventory_discrepancy' => 'Расхождение склада',
            'manual_balance_edit' => 'Ручная правка баланса',
            default => 'Нарушение протокола',
        };
    }

    private function normalizeSeverity(?string $severity): string
    {
        return match ($severity) {
            'critical', 'high' => 'high',
            'low', 'info' => 'low',
            default => 'medium',
        };
    }

    private function minutesAgo($timestamp): int
    {
        if (! $timestamp) {
            return 0;
        }

        return (int) abs(Carbon::parse($timestamp)->diffInMinutes(now()));
    }
}

