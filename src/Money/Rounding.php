<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger\Money;

use Brick\Math\RoundingMode;

enum Rounding: string
{
    case HalfEven = 'half_even';
    case HalfUp = 'half_up';
    case Down = 'down';

    public function brick(): RoundingMode
    {
        return match ($this) {
            self::HalfEven => RoundingMode::HalfEven,
            self::HalfUp => RoundingMode::HalfUp,
            self::Down => RoundingMode::Down,
        };
    }
}
