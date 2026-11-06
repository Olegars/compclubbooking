<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\AdminRatingEntry;
use App\Models\AdminRatingEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class AdminRatingService
{
    /**
     * @return list<string>
     */
    public function ratedRoles(): array
    {
        return [Admin::ROLE_ADMIN, Admin::ROLE_INTERN, Admin::ROLE_SUPERVISOR];
    }

    public function create(string $name, int $points, string $direction): AdminRatingEvent
    {
        return AdminRatingEvent::query()->create([
            'name' => $this->normalizeName($name),
            'points' => $this->normalizePoints($points),
            'direction' => $this->normalizeDirection($direction),
        ]);
    }

    public function update(AdminRatingEvent $event, string $name, int $points, string $direction): AdminRatingEvent
    {
        $event->fill([
            'name' => $this->normalizeName($name, $event->id),
            'points' => $this->normalizePoints($points),
            'direction' => $this->normalizeDirection($direction),
        ]);
        $event->save();

        return $event;
    }

    public function delete(AdminRatingEvent $event): void
    {
        $event->delete();
    }

    public function apply(Admin $actor, Admin $target, AdminRatingEvent $event): AdminRatingEntry
    {
        if (! in_array($actor->role, [Admin::ROLE_SUPERVISOR, Admin::ROLE_OWNER], true)) {
            throw new RuntimeException('Событие проводит управляющий или владелец.');
        }
        if ((int) $actor->id === (int) $target->id) {
            throw new RuntimeException('Нельзя провести событие себе.');
        }
        if ($target->isFired()) {
            throw new RuntimeException('Уволенному рейтинг не меняется.');
        }
        if (! in_array($target->role, $this->ratedRoles(), true)) {
            throw new RuntimeException('Рейтинг есть у админа зала, стажёра и управляющего.');
        }

        return AdminRatingEntry::query()->create([
            'admin_id' => $target->id,
            'event_id' => $event->id,
            'event_name' => $event->name,
            'points' => $event->signedPoints(),
            'created_by' => $actor->id,
        ]);
    }

    public function total(Admin $admin): int
    {
        if (! Schema::hasTable('admin_rating_entries')) {
            return 0;
        }

        return (int) AdminRatingEntry::query()
            ->where('admin_id', $admin->id)
            ->sum('points');
    }

    /**
     * @return array{total:int,history:list<array{id:int,event_name:string,points:int,created_at:?string,author:?string}>}|null
     */
    public function cabinet(Admin $admin): ?array
    {
        if ($admin->isOwner() || $admin->isStoreRole() || ! Schema::hasTable('admin_rating_entries')) {
            return null;
        }
        if (! in_array($admin->role, $this->ratedRoles(), true)) {
            return null;
        }

        $history = AdminRatingEntry::query()
            ->with('author:id,name')
            ->where('admin_id', $admin->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (AdminRatingEntry $row) => [
                'id' => $row->id,
                'event_name' => $row->event_name,
                'points' => (int) $row->points,
                'created_at' => $row->created_at?->timezone(config('app.timezone'))->format('d.m.Y H:i'),
                'author' => $row->author?->name,
            ])
            ->all();

        return [
            'total' => $this->total($admin),
            'history' => $history,
        ];
    }

    /**
     * @return array{events:list<array<string,mixed>>,admins:list<array<string,mixed>>,recent:list<array<string,mixed>>}
     */
    public function page(): array
    {
        $events = AdminRatingEvent::query()
            ->orderBy('name')
            ->get()
            ->map(fn (AdminRatingEvent $event) => [
                'id' => $event->id,
                'name' => $event->name,
                'points' => (int) $event->points,
                'direction' => $event->direction,
                'signed' => $event->signedPoints(),
            ])
            ->all();

        $totals = AdminRatingEntry::query()
            ->select('admin_id', DB::raw('SUM(points) as total'))
            ->groupBy('admin_id')
            ->pluck('total', 'admin_id');

        $admins = Admin::query()
            ->whereIn('role', $this->ratedRoles())
            ->whereNull('fired_at')
            ->orderBy('name')
            ->get(['id', 'name', 'role'])
            ->map(fn (Admin $admin) => [
                'id' => $admin->id,
                'name' => $admin->name,
                'role' => $admin->role,
                'role_label' => $admin->roleLabel(),
                'total' => (int) ($totals[$admin->id] ?? 0),
            ])
            ->sortByDesc('total')
            ->values()
            ->all();

        $recent = AdminRatingEntry::query()
            ->with(['admin:id,name', 'author:id,name'])
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (AdminRatingEntry $row) => [
                'id' => $row->id,
                'admin_name' => $row->admin?->name,
                'event_name' => $row->event_name,
                'points' => (int) $row->points,
                'author' => $row->author?->name,
                'created_at' => $row->created_at?->timezone(config('app.timezone'))->format('d.m.Y H:i'),
            ])
            ->all();

        return [
            'events' => $events,
            'admins' => $admins,
            'recent' => $recent,
        ];
    }

    private function normalizeName(string $name, ?int $ignoreId = null): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name) > 80) {
            throw new RuntimeException('Название события — от 1 до 80 символов.');
        }

        $taken = AdminRatingEvent::query()
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->pluck('name');

        foreach ($taken as $existing) {
            if (mb_strtolower((string) $existing) === mb_strtolower($name)) {
                throw new RuntimeException('Событие с таким названием уже есть.');
            }
        }

        return $name;
    }

    private function normalizePoints(int $points): int
    {
        if ($points < 1 || $points > 100000) {
            throw new RuntimeException('Баллы события — от 1 до 100 000.');
        }

        return $points;
    }

    private function normalizeDirection(string $direction): string
    {
        if (! in_array($direction, [AdminRatingEvent::ADD, AdminRatingEvent::SUBTRACT], true)) {
            throw new RuntimeException('Укажите, начислять или снимать баллы.');
        }

        return $direction;
    }
}
