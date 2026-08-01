<?php

namespace App\Services\Fan\Drivers;

use App\Models\RelayBoard;

interface FanActuatorInterface
{
    /**
     * Apply desired power (0–100). On/off relays treat any value > 0 as ON.
     *
     * @throws \Throwable on transport / protocol failure
     */
    public function apply(RelayBoard $board, int $channel, int $power): void;
}
