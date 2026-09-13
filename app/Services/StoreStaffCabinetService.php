<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\StoreBuiltPc;
use App\Models\StoreEstimate;
use App\Models\StoreOrder;
use App\Models\StoreWarranty;
use App\Support\AdminAlerts;
use App\Support\AdminLocation;

class StoreStaffCabinetService
{
    /**
     * @return array<string, mixed>|null
     */
    public function desk(Admin $admin): ?array
    {
        if (! $admin->isStoreRole()) {
            return null;
        }

        $clubId = AdminLocation::id($admin);
        $role = $admin->role;
        $isAssembler = $role === 'assembler';
        $canManage = $admin->canManageStoreCatalog();

        $openStatuses = ['new', 'assembling', 'ready'];
        $ordersQuery = StoreOrder::query()
            ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
            ->with(['client:id,name,phone', 'assignee:id,name'])
            ->whereIn('status', $openStatuses)
            ->latest();

        if ($isAssembler) {
            $ordersQuery->where(function ($q) use ($admin) {
                $q->where('assignee_id', $admin->id)
                    ->orWhere(function ($open) {
                        $open->whereNull('assignee_id')->where('status', 'new');
                    });
            });
        }

        $orders = $ordersQuery->limit(12)->get()->map(fn (StoreOrder $order) => [
            'id' => $order->id,
            'status' => $order->status,
            'total' => (float) $order->total,
            'notes' => $order->notes,
            'client' => $order->client?->name,
            'assignee' => $order->assignee?->name,
            'assignee_id' => $order->assignee_id ? (int) $order->assignee_id : null,
            'mine' => (int) $order->assignee_id === (int) $admin->id,
            'can_take' => $isAssembler && $order->status === 'new',
            'created_at' => $order->created_at?->toIso8601String(),
        ])->values()->all();

        $pcsQuery = StoreBuiltPc::query()
            ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
            ->with(['client:id,name', 'assembler:id,name'])
            ->where('status', 'assembling')
            ->latest();

        if ($isAssembler) {
            $pcsQuery->where(function ($q) use ($admin) {
                $q->where('assembled_by', $admin->id)->orWhereNull('assembled_by');
            });
        }

        $pcs = $pcsQuery->limit(8)->get()->map(fn (StoreBuiltPc $pc) => [
            'id' => $pc->id,
            'title' => $pc->title,
            'serial_number' => $pc->serial_number,
            'client' => $pc->client?->name,
            'assembler' => $pc->assembler?->name,
            'status' => $pc->status,
        ])->values()->all();

        $warrantiesQuery = StoreWarranty::query()
            ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
            ->with(['client:id,name', 'builtPc:id,title,serial_number,assembled_by'])
            ->where('status', 'claimed')
            ->latest();

        if ($isAssembler) {
            $warrantiesQuery->where(function ($q) use ($admin) {
                $q->whereHas('order', fn ($oq) => $oq->where('assignee_id', $admin->id))
                    ->orWhereHas('builtPc', fn ($bq) => $bq->where('assembled_by', $admin->id));
            });
        }

        $warranties = $warrantiesQuery->limit(8)->get()->map(fn (StoreWarranty $row) => [
            'id' => $row->id,
            'product_name' => $row->product_name ?: $row->builtPc?->title,
            'serial' => $row->serial ?: $row->builtPc?->serial_number,
            'client' => $row->client?->name,
            'status' => $row->status,
        ])->values()->all();

        $estimates = [];
        if ($canManage || $isAssembler) {
            $estimateStatuses = $isAssembler ? ['ready'] : ['draft', 'agreed', 'procuring', 'ready'];
            $estimates = StoreEstimate::query()
                ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
                ->with('client:id,name')
                ->whereIn('status', $estimateStatuses)
                ->latest()
                ->limit(8)
                ->get()
                ->map(fn (StoreEstimate $row) => [
                    'id' => $row->id,
                    'title' => $row->title,
                    'status' => $row->status,
                    'status_label' => StoreEstimate::STATUS_LABELS[$row->status] ?? $row->status,
                    'client' => $row->client?->name,
                    'sale_total' => (float) $row->sale_total,
                ])
                ->values()
                ->all();
        }

        $countsBase = StoreOrder::query()->when($clubId, fn ($q) => $q->where('club_id', $clubId));

        $team = [];
        if ($role === 'senior_manager') {
            $team = Admin::query()
                ->whereIn('role', Admin::STORE_ONLY_ROLES)
                ->where(function ($q) use ($clubId) {
                    $q->whereNull('club_id');
                    if ($clubId) {
                        $q->orWhere('club_id', $clubId);
                    }
                })
                ->whereNull('fired_at')
                ->orderBy('role')
                ->orderBy('name')
                ->get(['id', 'name', 'role'])
                ->map(fn (Admin $row) => [
                    'id' => $row->id,
                    'name' => $row->name,
                    'role' => $row->role,
                    'role_label' => $row->roleLabel(),
                ])
                ->values()
                ->all();
        }

        $alerts = AdminAlerts::counts();

        return [
            'role' => $role,
            'role_label' => $admin->roleLabel(),
            'headline' => match ($role) {
                'assembler' => 'Сборки и заказы, которые ждут вас',
                'senior_manager' => 'Контроль магазина: очередь, гарантии, смена',
                default => 'Сметы, заказы и выдача',
            },
            'permissions' => [
                'can_manage' => $canManage,
                'can_assign' => $canManage,
                'can_cancel' => $admin->canCancelStoreOrders(),
                'can_close_warranty' => $admin->canCloseWarranties(),
                'can_take_orders' => $isAssembler,
            ],
            'counts' => [
                'orders_new' => (clone $countsBase)->where('status', 'new')->count(),
                'orders_assembling' => (clone $countsBase)->where('status', 'assembling')->count(),
                'orders_ready' => (clone $countsBase)->where('status', 'ready')->count(),
                'orders_mine' => (clone $countsBase)->where('assignee_id', $admin->id)->whereIn('status', $openStatuses)->count(),
                'pcs_assembling' => StoreBuiltPc::query()
                    ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
                    ->where('status', 'assembling')
                    ->count(),
                'warranties_claimed' => StoreWarranty::query()
                    ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
                    ->where('status', 'claimed')
                    ->count(),
                'estimates_active' => StoreEstimate::query()
                    ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
                    ->whereIn('status', ['draft', 'agreed', 'procuring', 'ready'])
                    ->count(),
                'avito_unread' => $canManage ? (int) ($alerts['avito_unread'] ?? 0) : 0,
            ],
            'orders' => $orders,
            'pcs' => $pcs,
            'warranties' => $warranties,
            'estimates' => $estimates,
            'team' => $team,
            'links' => $this->links($admin),
        ];
    }

    /**
     * @return list<array{href: string, label: string, hint: string}>
     */
    private function links(Admin $admin): array
    {
        $links = [
            ['href' => '/admin/store/orders', 'label' => 'Заказы', 'hint' => 'Сборка и выдача'],
            ['href' => '/admin/store/built-pcs', 'label' => 'Готовые ПК', 'hint' => 'Карточки сборок'],
            ['href' => '/admin/store/warehouse', 'label' => 'Склад', 'hint' => 'Комплектующие'],
            ['href' => '/admin/store/warranty', 'label' => 'Гарантия', 'hint' => 'Обращения'],
        ];

        if ($admin->canManageStoreCatalog()) {
            array_unshift($links, ['href' => '/admin/store/estimates', 'label' => 'Сметы', 'hint' => 'Комплектации']);
            $links[] = ['href' => '/admin/store/clients', 'label' => 'Клиенты', 'hint' => 'Карточки покупателей'];
            $links[] = ['href' => '/admin/store/avito', 'label' => 'Avito', 'hint' => 'Объявления и чат'];
        }

        return $links;
    }
}
