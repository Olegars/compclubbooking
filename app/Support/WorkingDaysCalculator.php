<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Срок по ст. 193 ТК РФ: два рабочих дня по производственному календарю РФ.
 * День вручения не считается. Срок истекает в 23:59 второго рабочего дня.
 * Акт о непредоставлении объяснений — строго после этого момента.
 */
class WorkingDaysCalculator
{
    public function deadlineAfterDelivery(CarbonInterface $deliveredAt, ?int $workingDays = null): Carbon
    {
        $workingDays ??= (int) config('staff_edo.explanation_working_days', 2);
        $zone = config('app.timezone', 'Europe/Moscow');
        $cursor = Carbon::parse($deliveredAt)->timezone($zone)->startOfDay()->addDay();
        $left = max(1, $workingDays);
        $guard = 0;

        while ($left > 0 && $guard < 400) {
            $guard++;
            if ($this->isWorkingDay($cursor)) {
                $left--;
                if ($left === 0) {
                    return $cursor->copy()->endOfDay();
                }
            }
            $cursor->addDay();
        }

        throw new \RuntimeException('Не удалось посчитать срок по производственному календарю.');
    }

    public function canIssueNoExplanationAct(CarbonInterface $deadline, CarbonInterface $now): bool
    {
        return Carbon::parse($now)->greaterThan(Carbon::parse($deadline));
    }

    public function isWorkingDay(CarbonInterface $day): bool
    {
        $date = Carbon::parse($day)->timezone(config('app.timezone', 'Europe/Moscow'))->toDateString();
        $weekends = config('staff_edo.working_weekends', []);
        if (in_array($date, $weekends, true)) {
            return true;
        }
        $daysOff = config('staff_edo.days_off', []);
        if (in_array($date, $daysOff, true)) {
            return false;
        }

        return ! Carbon::parse($date)->isWeekend();
    }
}
