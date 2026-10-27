<?php

declare(strict_types=1);

namespace Noria\Ledger\Assets;

enum StubConvention: string
{
    case ActualDays = 'actual_days';
    case CompletedPeriods = 'completed_periods';
    case FullPeriod = 'full_period';
}
