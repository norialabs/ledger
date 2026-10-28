<?php

declare(strict_types=1);

namespace Noria\Ledger\Inventory;

enum ValuationMethod: string
{
    case Fifo = 'fifo';
    case MovingAverage = 'moving_average';
    case Specific = 'specific';
}
