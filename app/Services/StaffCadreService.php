<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\CadreReport;
use App\Models\StaffCadreEvent;
use App\Models\StaffEmploymentProfile;
use App\Models\StaffLedger;
use App\Support\RussianIdentity;
use App\Support\WorkingDaysCalculator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class StaffCadreService
{
    public function __construct(
        private readonly WorkingDaysCalculator $days,
        private readonly StaffCadreXml $xml,
    ) {
    }

    public function recordHire(Admin $admin): StaffCadreEvent
    {
        $last = StaffCadreEvent::query()->where('admin_id', $admin->id)->latest('id')->first();
        if ($last && $last->event_type === StaffCadreEvent::HIRE && $last->status !== 'cancelled') {
            return $last;
        }

        return $this->openEvent($admin, StaffCadreEvent::HIRE, null);
    }

    public function recordFire(Admin $admin, string $reasonCode): ?StaffCadreEvent
    {
        if (! $this->tracked($admin)) {
            return null;
        }

        $reasons = config('staff_cadre.fire_reasons', []);
        if (! isset($reasons[$reasonCode])) {
            throw new RuntimeException('Укажите основание прекращения договора.');
        }

        $last = StaffCadreEvent::query()->where('admin_id', $admin->id)->latest('id')->first();
        if ($last && $last->event_type === StaffCadreEvent::FIRE && $last->event_date?->isSameDay(now())) {
            return $last;
        }

        return $this->openEvent($admin, StaffCadreEvent::FIRE, $reasonCode);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveRequisites(Admin $admin, array $data): StaffEmploymentProfile
    {
        $profile = StaffEmploymentProfile::query()->firstOrCreate(
            ['admin_id' => $admin->id],
            [
                'full_name' => $admin->name,
                'accepted_rule_ids' => [],
                'accepted_fire_rule_ids' => [],
                'status' => StaffEmploymentProfile::STATUS_APPROVED,
            ]
        );

        $profile->snils = RussianIdentity::normalizeSnils((string) $data['snils']);
        $profile->inn = RussianIdentity::normalizeInn((string) $data['inn']);
        $profile->gender = $data['gender'];
        if (filled($data['okz_code'] ?? null)) {
            $profile->okz_code = $data['okz_code'];
            $known = collect(config('staff_cadre.okz'))->firstWhere('code', $data['okz_code']);
            $profile->work_function_title = $data['work_function_title']
                ?? ($known['title'] ?? $profile->work_function_title);
        }
        if (array_key_exists('part_time_code', $data)) {
            $profile->part_time_code = filled($data['part_time_code']) ? $data['part_time_code'] : null;
        }
        $profile->save();

        return $profile;
    }

    /**
     * @param  list<int>  $eventIds
     */
    public function generateEfs1(array $eventIds, Admin $actor): CadreReport
    {
        $ids = array_values(array_unique(array_map('intval', $eventIds)));
        if ($ids === []) {
            throw new RuntimeException('Выберите кадровые мероприятия.');
        }

        $events = StaffCadreEvent::query()
            ->with('admin.employmentProfile')
            ->whereIn('id', $ids)
            ->orderBy('event_date')
            ->orderBy('id')
            ->get();

        if ($events->count() !== count($ids)) {
            throw new RuntimeException('Часть мероприятий не найдена.');
        }

        $locked = $events->first(fn (StaffCadreEvent $event) => $event->status === StaffCadreEvent::SUBMITTED);
        if ($locked) {
            throw new RuntimeException('Сданное мероприятие повторно не выгружается.');
        }

        $employer = $this->employer(true);
        $people = [];
        $problems = [];

        foreach ($events->groupBy('admin_id') as $adminId => $rows) {
            try {
                $people[] = $this->personBlock($rows->all());
            } catch (RuntimeException $e) {
                $name = $rows->first()?->admin?->name ?: ('сотрудник '.$adminId);
                $problems[] = $name.': '.$e->getMessage();
            }
        }

        if ($problems !== []) {
            throw new RuntimeException(implode(' ', $problems));
        }

        $bytes = $this->xml->efs1($employer, $people);
        $stamp = now()->format('YmdHis');

        return DB::transaction(function () use ($events, $bytes, $actor, $stamp, $employer) {
            $relative = 'cadre-reports/'.now()->format('Y/m').'/EFS1_'.$employer['inn'].'_'.$stamp.'.xml';
            Storage::disk('local')->put($relative, $bytes);

            $report = CadreReport::query()->create([
                'report_type' => CadreReport::EFS1,
                'period_year' => (int) now()->year,
                'period_month' => (int) now()->month,
                'file_path' => $relative,
                'records_count' => $events->count(),
                'xml_hash' => hash('sha256', $bytes),
                'created_by' => $actor->id,
                'status' => CadreReport::EXPORTED,
            ]);

            foreach ($events as $event) {
                $event->status = StaffCadreEvent::EXPORTED;
                $event->exported_at = now();
                $event->report_id = $report->id;
                $event->save();
            }

            return $report;
        });
    }

    public function generatePersRecords(int $year, int $month, Admin $actor): CadreReport
    {
        if ($month < 1 || $month > 12) {
            throw new RuntimeException('Укажите месяц отчёта.');
        }

        $employer = $this->employer(false);
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $employees = Admin::query()
            ->with('employmentProfile')
            ->where('is_official_employee', true)
            ->where(function ($query) use ($end) {
                $query->whereNull('hired_at')->orWhere('hired_at', '<=', $end);
            })
            ->where(function ($query) use ($start) {
                $query->whereNull('fired_at')->orWhere('fired_at', '>=', $start);
            })
            ->orderBy('name')
            ->get();

        if ($employees->isEmpty()) {
            throw new RuntimeException('За этот месяц нет оформленных сотрудников.');
        }

        $rows = [];
        $problems = [];
        foreach ($employees as $employee) {
            try {
                $rows[] = $this->persRow($employee, $start, $end);
            } catch (RuntimeException $e) {
                $problems[] = $employee->name.': '.$e->getMessage();
            }
        }
        if ($problems !== []) {
            throw new RuntimeException(implode(' ', $problems));
        }

        $bytes = $this->xml->persRecords($employer, $year, $month, $rows);
        $stamp = now()->format('YmdHis');

        return DB::transaction(function () use ($bytes, $actor, $year, $month, $stamp, $employer, $rows) {
            $relative = 'cadre-reports/'.$year.'/NO_PERSSVFL_'.$employer['inn'].'_'.$year.str_pad((string) $month, 2, '0', STR_PAD_LEFT).'_'.$stamp.'.xml';
            Storage::disk('local')->put($relative, $bytes);

            return CadreReport::query()->create([
                'report_type' => CadreReport::PERS,
                'period_year' => $year,
                'period_month' => $month,
                'file_path' => $relative,
                'records_count' => count($rows),
                'xml_hash' => hash('sha256', $bytes),
                'created_by' => $actor->id,
                'status' => CadreReport::EXPORTED,
            ]);
        });
    }

    public function markSubmitted(CadreReport $report): void
    {
        if ($report->status === CadreReport::SUBMITTED) {
            return;
        }

        $report->status = CadreReport::SUBMITTED;
        $report->submitted_at = now();
        $report->save();

        StaffCadreEvent::query()
            ->where('report_id', $report->id)
            ->update([
                'status' => StaffCadreEvent::SUBMITTED,
                'updated_at' => now(),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function screen(): array
    {
        $previous = now()->subMonth();

        return [
            'employer_ready' => $this->employerReady(),
            'employer_hint' => $this->employerReady()
                ? 'Реквизиты страхователя заполнены.'
                : 'Заполните CLUB_LEGAL_ENTITY, CLUB_LEGAL_INN, CLUB_SFR_REG и CLUB_TAX_OFFICE.',
            'alerts' => $this->alerts(),
            'events' => StaffCadreEvent::query()
                ->with('admin:id,name')
                ->latest('id')
                ->limit(80)
                ->get()
                ->map(fn (StaffCadreEvent $event) => $this->eventRow($event))
                ->all(),
            'reports' => CadreReport::query()
                ->latest('id')
                ->limit(40)
                ->get()
                ->map(fn (CadreReport $report) => [
                    'id' => $report->id,
                    'type' => $report->report_type,
                    'type_label' => $report->report_type === CadreReport::EFS1
                        ? 'ЕФС-1 подраздел 1.1'
                        : 'Персонифицированные сведения',
                    'period' => $report->period_month
                        ? sprintf('%02d.%d', $report->period_month, $report->period_year)
                        : (string) $report->period_year,
                    'records_count' => $report->records_count,
                    'status' => $report->status,
                    'status_label' => $report->status === CadreReport::SUBMITTED ? 'Отмечен как сдан' : 'Файл выгружен',
                    'created_at' => $report->created_at?->toIso8601String(),
                ])
                ->all(),
            'pers_year' => (int) $previous->year,
            'pers_month' => (int) $previous->month,
            'okz_options' => $this->okzOptions(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function alerts(): array
    {
        if (! $this->tablesReady()) {
            return [];
        }

        $alerts = [];
        $events = StaffCadreEvent::query()
            ->with('admin:id,name')
            ->whereIn('status', [StaffCadreEvent::PENDING, StaffCadreEvent::EXPORTED])
            ->orderBy('deadline_at')
            ->limit(12)
            ->get();

        foreach ($events as $event) {
            $name = RussianIdentity::shortName($event->admin?->name ?: 'Сотрудник');
            $action = $event->event_type === StaffCadreEvent::FIRE ? 'уволен' : 'принят';
            $alerts[] = [
                'id' => 'event-'.$event->id,
                'tone' => $this->tone($event->deadline_at),
                'title' => $name.' '.$action.' — ЕФС-1 '.$this->deadlinePhrase($event->deadline_at),
                'hint' => $event->status === StaffCadreEvent::EXPORTED ? 'Файл выгружен' : 'Черновик',
                'status' => $event->status,
            ];
        }

        $previous = now()->subMonth();
        $due = Carbon::create((int) now()->year, (int) now()->month, 25)->endOfDay();
        $hasStaff = Admin::query()->where('is_official_employee', true)->exists();
        $submitted = CadreReport::query()
            ->where('report_type', CadreReport::PERS)
            ->where('period_year', (int) $previous->year)
            ->where('period_month', (int) $previous->month)
            ->where('status', CadreReport::SUBMITTED)
            ->exists();

        if ($hasStaff && ! $submitted) {
            $monthName = $previous->locale('ru')->translatedFormat('F');
            $alerts[] = [
                'id' => 'pers-'.$previous->format('Y-m'),
                'tone' => $this->tone($due),
                'title' => 'Персонифицированные сведения за '.$monthName.' — до '.$due->format('d.m'),
                'hint' => 'КНД 1151162, строка 070',
                'status' => 'pending',
            ];
        }

        return $alerts;
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(StaffCadreEvent $event): array
    {
        $event->load('admin.employmentProfile');
        $profile = $event->admin?->employmentProfile;
        $reasons = config('staff_cadre.fire_reasons', []);

        return [
            'event' => $this->eventRow($event),
            'full_name' => $profile?->full_name ?: $event->admin?->name,
            'snils' => $profile?->snils,
            'inn' => $profile?->inn,
            'birth_date' => $profile?->birth_date?->toDateString(),
            'gender' => $profile?->gender === 'female' ? 'Женский' : ($profile?->gender === 'male' ? 'Мужской' : null),
            'passport' => trim(($profile?->passport_series ?: '').' '.($profile?->passport_number ?: '')),
            'issued_by' => $profile?->issued_by,
            'issued_at' => $profile?->issued_at?->toDateString(),
            'department_code' => $profile?->department_code,
            'fire_reason' => $reasons[$event->fire_reason_code]['label'] ?? null,
            'employer' => config('staff_cadre.employer.name'),
        ];
    }

    /**
     * @return list<array{code: string, title: string}>
     */
    public function okzOptions(): array
    {
        $options = [];
        foreach (config('staff_cadre.okz', []) as $row) {
            $options[$row['code']] = $row;
        }

        return array_values($options);
    }

    private function openEvent(Admin $admin, string $type, ?string $reasonCode): StaffCadreEvent
    {
        $this->ensureFunction($admin);
        $admin->load('employmentProfile');
        $profile = $admin->employmentProfile;
        $today = now()->startOfDay();
        $deadline = $this->days->nextWorkingDay($today)->setTime(18, 0);
        $year = (int) $today->year;
        $seq = StaffCadreEvent::query()->whereYear('order_date', $year)->count() + 1;

        return StaffCadreEvent::query()->create([
            'admin_id' => $admin->id,
            'club_id' => $admin->club_id,
            'event_type' => $type,
            'event_date' => $today->toDateString(),
            'order_number' => $seq.'-к/'.$year,
            'order_date' => $today->toDateString(),
            'okz_code' => $profile?->okz_code ?: '4222.0',
            'work_function_title' => $profile?->work_function_title ?: 'Администратор зала',
            'part_time_code' => $profile?->part_time_code,
            'fire_reason_code' => $reasonCode,
            'status' => StaffCadreEvent::PENDING,
            'deadline_at' => $deadline,
        ]);
    }

    private function ensureFunction(Admin $admin): void
    {
        $profile = $admin->employmentProfile;
        if (! $profile || filled($profile->okz_code)) {
            if ($profile && $admin->pay_type === 'shift' && ! filled($profile->part_time_code)) {
                $profile->part_time_code = 'НЕПД';
                $profile->save();
            }

            return;
        }

        $map = config('staff_cadre.okz.'.$admin->role, config('staff_cadre.okz.admin'));
        $profile->okz_code = $map['code'];
        $profile->work_function_title = $map['title'];
        if ($admin->pay_type === 'shift' && ! filled($profile->part_time_code)) {
            $profile->part_time_code = 'НЕПД';
        }
        $profile->save();
    }

    private function tracked(Admin $admin): bool
    {
        if ($admin->is_official_employee) {
            return true;
        }

        return StaffCadreEvent::query()->where('admin_id', $admin->id)->exists();
    }

    /**
     * @param  list<StaffCadreEvent>  $events
     * @return array<string, mixed>
     */
    private function personBlock(array $events): array
    {
        $admin = $events[0]->admin;
        $identity = $this->identity($admin);
        $eventRows = [];

        foreach ($events as $event) {
            $profile = $admin->employmentProfile;
            if ($event->status === StaffCadreEvent::PENDING && $profile) {
                $event->okz_code = $profile->okz_code ?: $event->okz_code;
                $event->work_function_title = $profile->work_function_title ?: $event->work_function_title;
                $event->part_time_code = $profile->part_time_code;
                $event->save();
            }

            $kind = match ($event->event_type) {
                StaffCadreEvent::FIRE => '5',
                StaffCadreEvent::TRANSFER => '2',
                default => '1',
            };
            $reason = null;
            if ($event->event_type === StaffCadreEvent::FIRE) {
                $reason = config('staff_cadre.fire_reasons.'.$event->fire_reason_code.'.sfr');
                if (! $reason) {
                    throw new RuntimeException('Не указано основание увольнения.');
                }
            }

            $eventRows[] = [
                'date' => $event->event_date?->toDateString(),
                'kind' => $kind,
                'title' => $event->work_function_title,
                'okz' => $event->okz_code,
                'order_number' => $event->order_number,
                'order_date' => $event->order_date?->toDateString(),
                'part_time' => $event->part_time_code,
                'fire_reason' => $reason,
            ];
        }

        return array_merge($identity, ['events' => $eventRows]);
    }

    /**
     * @return array<string, mixed>
     */
    private function persRow(Admin $employee, Carbon $start, Carbon $end): array
    {
        $identity = $this->identity($employee);
        $amount = (float) StaffLedger::query()
            ->where('admin_id', $employee->id)
            ->where('type', StaffLedger::TYPE_ACCRUAL)
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount');

        return array_merge($identity, ['amount' => round($amount, 2)]);
    }

    /**
     * @return array{snils: string, inn: string, last: string, first: string, middle: string, birth_date: string, gender_code: string}
     */
    private function identity(Admin $admin): array
    {
        $profile = $admin->employmentProfile;
        if (! $profile) {
            throw new RuntimeException('нет анкеты для СФР.');
        }
        if (! filled($profile->snils)) {
            throw new RuntimeException('не заполнен СНИЛС.');
        }
        if (! filled($profile->inn)) {
            throw new RuntimeException('не заполнен ИНН.');
        }

        $snils = RussianIdentity::normalizeSnils((string) $profile->snils);
        $inn = RussianIdentity::normalizeInn((string) $profile->inn);
        $fio = RussianIdentity::splitFio($profile->full_name ?: $admin->name);
        if (! $profile->birth_date) {
            throw new RuntimeException('не заполнена дата рождения.');
        }
        $gender = match ($profile->gender) {
            'male' => '1',
            'female' => '2',
            default => throw new RuntimeException('не указан пол.'),
        };

        return [
            'snils' => $snils,
            'inn' => $inn,
            'last' => $fio['last'],
            'first' => $fio['first'],
            'middle' => $fio['middle'],
            'birth_date' => $profile->birth_date->toDateString(),
            'gender_code' => $gender,
        ];
    }

    /**
     * @return array{name: string, inn: string, kpp: string, sfr_reg_number: string, tax_office: string}
     */
    private function employer(bool $forEfs): array
    {
        $config = config('staff_cadre.employer', []);
        $inn = preg_replace('/\D+/', '', (string) ($config['inn'] ?? '')) ?? '';
        $name = trim((string) ($config['name'] ?? ''));
        $reg = trim((string) ($config['sfr_reg_number'] ?? ''));
        $office = trim((string) ($config['tax_office'] ?? ''));

        if (! RussianIdentity::employerInnOk($inn) || $name === '') {
            throw new RuntimeException('Заполните наименование и ИНН работодателя в настройках клуба.');
        }
        if ($forEfs && $reg === '') {
            throw new RuntimeException('Заполните регистрационный номер СФР (CLUB_SFR_REG).');
        }
        if (! $forEfs && $office === '') {
            throw new RuntimeException('Заполните код налоговой инспекции (CLUB_TAX_OFFICE).');
        }

        return [
            'name' => $name,
            'inn' => $inn,
            'kpp' => preg_replace('/\D+/', '', (string) ($config['kpp'] ?? '')) ?? '',
            'sfr_reg_number' => $reg,
            'tax_office' => $office !== '' ? $office : '0000',
        ];
    }

    private function employerReady(): bool
    {
        try {
            $this->employer(true);
            $this->employer(false);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function eventRow(StaffCadreEvent $event): array
    {
        $labels = [
            StaffCadreEvent::HIRE => 'Приём',
            StaffCadreEvent::FIRE => 'Увольнение',
            StaffCadreEvent::TRANSFER => 'Перевод',
        ];
        $statuses = [
            StaffCadreEvent::PENDING => 'Черновик',
            StaffCadreEvent::EXPORTED => 'Файл выгружен',
            StaffCadreEvent::SUBMITTED => 'Отмечен как сдан',
        ];

        return [
            'id' => $event->id,
            'admin_id' => $event->admin_id,
            'name' => $event->admin?->name,
            'event_type' => $event->event_type,
            'event_label' => $labels[$event->event_type] ?? $event->event_type,
            'event_date' => $event->event_date?->toDateString(),
            'order_number' => $event->order_number,
            'order_date' => $event->order_date?->toDateString(),
            'okz_code' => $event->okz_code,
            'work_function_title' => $event->work_function_title,
            'part_time_code' => $event->part_time_code,
            'status' => $event->status,
            'status_label' => $statuses[$event->status] ?? $event->status,
            'deadline_at' => $event->deadline_at?->toIso8601String(),
            'deadline_label' => $this->deadlinePhrase($event->deadline_at),
            'can_export' => $event->status !== StaffCadreEvent::SUBMITTED,
        ];
    }

    private function deadlinePhrase(?Carbon $deadline): string
    {
        if (! $deadline) {
            return 'срок не задан';
        }

        $zone = config('app.timezone', 'Europe/Moscow');
        $end = $deadline->copy()->timezone($zone);
        $today = now()->timezone($zone)->startOfDay();
        $day = $end->copy()->startOfDay();

        if ($day->equalTo($today)) {
            return 'до 18:00 сегодня';
        }
        if ($day->equalTo($today->copy()->addDay())) {
            return 'до 18:00 завтра';
        }
        if ($end->isPast()) {
            return 'срок прошёл '.$end->format('d.m');
        }

        return 'до 18:00 '.$end->format('d.m');
    }

    private function tone(?Carbon $deadline): string
    {
        if (! $deadline) {
            return 'upcoming';
        }
        if ($deadline->isPast()) {
            return 'overdue';
        }
        if ($deadline->lte(now()->addDay())) {
            return 'due_soon';
        }

        return 'upcoming';
    }

    private function tablesReady(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable('staff_cadre_events')
            && \Illuminate\Support\Facades\Schema::hasTable('cadre_reports');
    }
}
