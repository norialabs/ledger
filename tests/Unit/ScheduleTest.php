<?php

declare(strict_types=1);

use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Scheduling\Schedule;

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

it('allocates production-weighted schedules using exact fractional and arbitrary-size weights', function () {
    $lines = Schedule::weighted(Money::of('100', new Currency('KES', 2)), ['2026-01-31', '2026-02-28', '2026-03-31'], ['0.1', '0.2', '0.3']);
    expect(array_map(fn ($line) => $line->amount->decimal(), $lines))->toBe(['16.67', '33.33', '50.00']);
    $large = Schedule::weighted(Money::of('0.01', new Currency('KES', 2)), ['2026-01-31', '2026-02-28'], ['9007199254740992', '9007199254740993']);
    expect(array_map(fn ($line) => $line->amount->decimal(), $large))->toBe(['0.00', '0.01']);
});

it('conserves declining-balance depreciation while preserving residual value', function () {
    $kes = new Currency('KES', 2);
    $dates = ['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'];
    $lines = Schedule::decliningBalance(Money::of('1000', $kes), Money::of('100', $kes), '0.5', $dates);
    expect(array_map(fn ($line) => $line->amount->decimal(), $lines))->toBe(['500.00', '250.00', '125.00', '25.00']);
    $switched = Schedule::decliningBalance(Money::of('1000', $kes), Money::of('100', $kes), '0.2', $dates, switchToStraightLine: true);
    expect(array_map(fn ($line) => $line->amount->decimal(), $switched))->toBe(['225.00', '225.00', '225.00', '225.00']);
});

it('rejects schedule dates that could change the interpretation of period rates', function () {
    Schedule::decliningBalance(Money::of('100', new Currency('KES', 2)), Money::of('0', new Currency('KES', 2)), '0.2', ['2026-02-28', '2026-01-31']);
})->throws(LedgerException::class, 'strictly increase');
