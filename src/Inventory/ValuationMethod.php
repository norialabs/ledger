<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger\Inventory;

enum ValuationMethod: string
{
    case Fifo = 'fifo';
    case MovingAverage = 'moving_average';
}
