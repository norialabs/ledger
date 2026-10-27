<?php

declare(strict_types=1);

use NoriaLabs\Ledger\Money\Currency;
use NoriaLabs\Ledger\Money\Money;
use NoriaLabs\Ledger\Scheduling\Schedule;

it('preserves the full amount across unequal calendar months and leap days', function () {
    $amount = Money::of('60', new Currency('KES', 2));
    $lines = Schedule::actualDaysByMonth($amount, '2028-01-31', '2028-03-01');
    expect(array_map(fn ($line) => $line->dueOn, $lines))->toBe(['2028-01-31', '2028-02-29', '2028-03-01']);
    expect(array_map(fn ($line) => $line->amount->decimal(), $lines))->toBe(['1.94', '56.13', '1.93']);
});

it('allocates final indivisible cents deterministically', function () {
    $lines = Schedule::equal(Money::of('1', new Currency('KES', 2)), ['2026-01-31', '2026-02-28', '2026-03-31']);
    expect(array_map(fn ($line) => $line->amount->decimal(), $lines))->toBe(['0.34', '0.33', '0.33']);
});
