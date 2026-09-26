<?php

namespace Tests\Unit;

use App\Support\WorkingDaysCalculator;
use Carbon\Carbon;
use Tests\TestCase;

class WorkingDaysCalculatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Europe/Moscow']);
    }
    public function test_two_working_days_skip_weekend_and_act_waits_until_next_morning(): void
    {
        $calc = new WorkingDaysCalculator();
        $delivered = Carbon::parse('2026-09-24 15:00:00', 'Europe/Moscow');

        $deadline = $calc->deadlineAfterDelivery($delivered);

        $this->assertSame('2026-09-28', $deadline->timezone('Europe/Moscow')->toDateString());
        $this->assertSame('23', $deadline->timezone('Europe/Moscow')->format('H'));
        $this->assertFalse($calc->canIssueNoExplanationAct(
            $deadline,
            Carbon::parse('2026-09-28 23:00:00', 'Europe/Moscow')
        ));
        $this->assertTrue($calc->canIssueNoExplanationAct(
            $deadline,
            Carbon::parse('2026-09-29 08:00:00', 'Europe/Moscow')
        ));
    }

    public function test_holiday_between_days_is_skipped(): void
    {
        config(['staff_edo.days_off' => array_merge(config('staff_edo.days_off', []), ['2026-09-25'])]);
        $calc = new WorkingDaysCalculator();

        $deadline = $calc->deadlineAfterDelivery(Carbon::parse('2026-09-24 15:00:00', 'Europe/Moscow'));

        $this->assertSame('2026-09-29', $deadline->timezone('Europe/Moscow')->toDateString());
    }

    public function test_new_year_weekday_is_not_a_working_day(): void
    {
        $calc = new WorkingDaysCalculator();

        $this->assertFalse($calc->isWorkingDay(Carbon::parse('2026-01-01')));
        $this->assertTrue($calc->isWorkingDay(Carbon::parse('2026-09-24')));
    }
}
