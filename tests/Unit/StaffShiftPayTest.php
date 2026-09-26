<?php

namespace Tests\Unit;

use App\Support\StaffShiftPay;
use Tests\TestCase;

class StaffShiftPayTest extends TestCase
{
    public function test_official_part_is_sixteen_day_hours_plus_eight_night_hours(): void
    {
        $this->assertSame('241.50', $this->money(StaffShiftPay::hourly()));
        $this->assertSame('3864.00', $this->money(StaffShiftPay::dayPay()));
        $this->assertSame('2318.40', $this->money(StaffShiftPay::nightPay()));
        $this->assertSame('6182.40', $this->money(StaffShiftPay::official()));
        $this->assertSame(
            StaffShiftPay::officialKopeks(),
            StaffShiftPay::dayKopeks() + StaffShiftPay::nightKopeks()
        );
    }

    public function test_bonus_is_only_the_part_above_the_floor(): void
    {
        $split = StaffShiftPay::split(8000);

        $this->assertNotNull($split);
        $this->assertSame('6182.40', $this->money($split['official']));
        $this->assertSame('1817.60', $this->money($split['bonus']));
    }

    public function test_rate_below_floor_blocks_the_split(): void
    {
        $this->assertNull(StaffShiftPay::split(6182));
        $this->assertNull(StaffShiftPay::split(6182.39));

        $exact = StaffShiftPay::split(6182.4);
        $this->assertNotNull($exact);
        $this->assertSame('0.00', $this->money($exact['bonus']));
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
