<?php

declare(strict_types=1);

namespace Noria\Ledger\Money;

enum RateType: string
{
    case Spot = 'spot';
    case Closing = 'closing';
    case Average = 'average';
    case Historical = 'historical';
}
