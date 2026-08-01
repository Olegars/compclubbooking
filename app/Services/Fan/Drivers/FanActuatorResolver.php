<?php

namespace App\Services\Fan\Drivers;

use App\Models\RelayBoard;
use InvalidArgumentException;

class FanActuatorResolver
{
    public function __construct(
        private readonly KinconyHttpActuator $kincony,
        private readonly DingtianHttpActuator $dingtian,
    ) {
    }

    public function resolve(RelayBoard $board): FanActuatorInterface
    {
        return match ($board->driver) {
            RelayBoard::DRIVER_KINCONY_HTTP => $this->kincony,
            RelayBoard::DRIVER_DINGTIAN_HTTP => $this->dingtian,
            default => throw new InvalidArgumentException("Unknown fan driver: {$board->driver}"),
        };
    }
}
