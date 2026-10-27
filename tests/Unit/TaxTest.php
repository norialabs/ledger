<?php

declare(strict_types=1);

use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\Rounding;
use Noria\Ledger\Tax\TaxCalculator;
use Noria\Ledger\Tax\TaxRule;

it('calculates exclusive and inclusive taxes with fractional quantities exactly', function () {
    $calculator = new TaxCalculator;
    $kes = new Currency('KES', 2);
    $exclusive = new TaxRule('EXAMPLE', '0.16', '2026-01-01', null, 'example:v1');
    $inclusive = new TaxRule('EXAMPLE', '0.16', '2026-01-01', null, 'example:v1', inclusive: true);
    $a = $calculator->calculate('100', '1.5', $kes, '2026-01-01', [$exclusive], Rounding::HalfEven);
    $b = $calculator->calculate('116', '1.5', $kes, '2026-01-01', [$inclusive], Rounding::HalfEven);
    expect($a->net->decimal())->toBe('150.00');
    expect($a->taxes['EXAMPLE']->decimal())->toBe('24.00');
    expect($a->gross->decimal())->toBe('174.00');
    expect($b->net->decimal())->toBe($a->net->decimal());
    expect($b->gross->decimal())->toBe($a->gross->decimal());
});

it('applies compound tax in the explicitly supplied order', function () {
    $result = (new TaxCalculator)->calculate('100', '1', new Currency('KES', 2), '2026-01-01', [
        new TaxRule('FIRST', '0.1', '2026-01-01', null, 'v1'),
        new TaxRule('SECOND', '0.2', '2026-01-01', null, 'v1', compound: true),
    ], Rounding::HalfEven);
    expect($result->taxes['SECOND']->decimal())->toBe('22.00');
    expect($result->gross->decimal())->toBe('132.00');
});

it('exposes rounding residuals instead of hiding an imbalance', function () {
    $result = (new TaxCalculator)->calculate('0.03', '1', new Currency('KES', 2), '2026-01-01', [
        new TaxRule('A', '0.2', '2026-01-01', null, 'v1'), new TaxRule('B', '0.2', '2026-01-01', null, 'v1'),
    ], Rounding::HalfEven);
    expect($result->roundingAdjustment->decimal())->toBe('-0.01');
    expect($result->net->plus($result->taxes['A'])->plus($result->taxes['B'])->plus($result->roundingAdjustment)->decimal())->toBe($result->gross->decimal());
});

it('rejects stale tax policies', function () {
    (new TaxCalculator)->calculate('100', '1', new Currency('KES', 2), '2026-01-01', [new TaxRule('EXAMPLE', '0.16', '2025-01-01', '2025-12-31', 'v1')], Rounding::HalfEven);
})->throws(LedgerException::class, 'not effective');
