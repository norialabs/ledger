<?php

declare(strict_types=1);

use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Finance\FinancialCalculator;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\Money;

it('accretes discounted lending at the effective rate and conserves principal and interest', function () {
    $kes = Currency::iso('KES');
    $rows = (new FinancialCalculator)->effectiveInterest(Money::of('950', $kes), '0.1', [
        ['due_on' => '2026-06-30', 'payment' => Money::of('100', $kes)],
        ['due_on' => '2026-12-31', 'payment' => Money::of('1039.50', $kes)],
    ]);
    expect($rows[0]['interest']->decimal())->toBe('95.00')
        ->and($rows[0]['closing']->decimal())->toBe('945.00')
        ->and($rows[1]['interest']->decimal())->toBe('94.50')
        ->and($rows[1]['closing']->decimal())->toBe('0.00');
});

it('measures lease liability from discounted unpaid payments and closes exactly', function (bool $advance, string $liability, string $commencement, int $rows) {
    $kes = Currency::iso('KES');
    $plan = (new FinancialCalculator)->lease('0.1', [
        ['due_on' => '2026-01-01', 'payment' => Money::of('100', $kes)],
        ['due_on' => '2026-02-01', 'payment' => Money::of('100', $kes)],
        ['due_on' => '2026-03-01', 'payment' => Money::of('100', $kes)],
    ], $advance);
    expect($plan['liability']->decimal())->toBe($liability)
        ->and($plan['commencement_payment']->decimal())->toBe($commencement)
        ->and($plan['rows'])->toHaveCount($rows)
        ->and($plan['rows'][$rows - 1]['closing']->decimal())->toBe('0.00');
    $carrying = $plan['liability'];
    foreach ($plan['rows'] as $row) {
        expect($row['opening']->decimal())->toBe($carrying->decimal());
        $carrying = $carrying->plus($row['interest'])->minus($row['payment']);
        expect($carrying->decimal())->toBe($row['closing']->decimal());
    }
})->with([[false, '248.69', '0.00', 3], [true, '173.55', '100.00', 2]]);

it('identifies lease quantization differences instead of leaving a phantom liability', function () {
    $kes = Currency::iso('KES');
    $plan = (new FinancialCalculator)->lease('0.1', [
        ['due_on' => '2026-01-31', 'payment' => Money::of('100', $kes)],
        ['due_on' => '2026-02-28', 'payment' => Money::of('100', $kes)],
        ['due_on' => '2026-03-31', 'payment' => Money::of('100', $kes)],
    ]);
    expect($plan['rows'][0]['interest']->decimal())->toBe('24.86')
        ->and($plan['rows'][0]['rounding_adjustment']->decimal())->toBe('-0.01');
});

it('discounts probability-weighted shortfalls before rounding the combined ECL', function () {
    $kes = Currency::iso('KES');
    $result = (new FinancialCalculator)->expectedCreditLoss('0.1', [['period' => 1, 'amount' => Money::of('1100', $kes)]], [
        ['weight' => '0.7', 'receipts' => [Money::of('1100', $kes)]],
        ['weight' => '0.3', 'receipts' => [Money::of('550', $kes)]],
    ]);
    expect($result['allowance']->decimal())->toBe('150.00')
        ->and($result['scenarios'][1]['shortfall']->decimal())->toBe('500.00');
    $tiny = (new FinancialCalculator)->expectedCreditLoss('0', [['period' => 0, 'amount' => Money::of('0.01', $kes)]], [
        ['weight' => '0.5', 'receipts' => [Money::of('0', $kes)]],
        ['weight' => '0.5', 'receipts' => [Money::of('0', $kes)]],
    ]);
    expect($tiny['allowance']->decimal())->toBe('0.01');
});

it('includes delayed recovery timing in credit loss measurement', function () {
    $kes = Currency::iso('KES');
    $result = (new FinancialCalculator)->expectedCreditLoss('0.1', [
        ['period' => 1, 'amount' => Money::of('110', $kes)],
        ['period' => 2, 'amount' => Money::of('0', $kes)],
    ], [['weight' => '1', 'receipts' => [Money::of('0', $kes), Money::of('110', $kes)]]]);
    expect($result['allowance']->decimal())->toBe('9.09');
});

it('allocates contract discounts by relative standalone selling price without losing cents', function () {
    $kes = Currency::iso('KES');
    $result = (new FinancialCalculator)->allocateRevenue(Money::of('100', $kes), [
        ['reference' => 'equipment', 'standalone_price' => '80'],
        ['reference' => 'service', 'standalone_price' => '40'],
    ]);
    expect($result['equipment']->decimal())->toBe('66.67')
        ->and($result['service']->decimal())->toBe('33.33');
    $large = (new FinancialCalculator)->allocateRevenue(Money::of('9007199254740993.01', $kes), [
        ['reference' => 'first', 'standalone_price' => '0.1'],
        ['reference' => 'second', 'standalone_price' => '0.2'],
    ]);
    expect($large['first']->plus($large['second'])->decimal())->toBe('9007199254740993.01');
});

it('rejects ambiguous financial plan dates and overpayments', function (string $case) {
    $kes = Currency::iso('KES');
    $payments = [['due_on' => '2026-01-31', 'payment' => Money::of($case === 'overpayment' ? '200' : '10', $kes)]];
    if ($case === 'dates') {
        $payments[] = $payments[0];
    }
    (new FinancialCalculator)->effectiveInterest(Money::of('100', $kes), $case === 'rate' ? '-0.1' : '0.1', $payments);
})->with(['dates', 'overpayment', 'rate'])->throws(LedgerException::class);

it('rejects ECL weights that are incomplete or exceed unity', function (string $weight) {
    $kes = Currency::iso('KES');
    (new FinancialCalculator)->expectedCreditLoss('0', [['period' => 1, 'amount' => Money::of('100', $kes)]], [
        ['weight' => $weight, 'receipts' => [Money::of('0', $kes)]],
    ]);
})->with(['0.9', '1.1', '-0.1'])->throws(LedgerException::class);

it('rejects duplicate performance obligations', function () {
    (new FinancialCalculator)->allocateRevenue(Money::of('100', Currency::iso('KES')), [
        ['reference' => 'service', 'standalone_price' => '80'],
        ['reference' => 'service', 'standalone_price' => '40'],
    ]);
})->throws(LedgerException::class);

/** @return non-empty-list<array{due_on: string, payment: Money}> */
function ratePlan(string $plan): array
{
    $payments = [];
    foreach (explode(' ', $plan) as $slot) {
        [$dueOn, $amount] = explode('=', $slot);
        $payments[] = ['due_on' => $dueOn, 'payment' => Money::of($amount, Currency::iso('KES'))];
    }

    return $payments;
}

it('finds the conserving effective rate nearest the exact root for multi-slot plans', function (string $cost, string $payments, string $rate) {
    $kes = Currency::iso('KES');
    $plan = ratePlan($payments);
    $calculator = new FinancialCalculator;
    $found = $calculator->effectiveRate(Money::of($cost, $kes), $plan);
    $rows = $calculator->effectiveInterest(Money::of($cost, $kes), $found, $plan);
    $last = $rows[count($rows) - 1] ?? throw new RuntimeException('Missing final effective-interest row.');
    expect($found)->toBe($rate)->and($last['closing']->decimal())->toBe('0.00')
        ->and(array_sum(array_map(fn (array $row): int => (int) (string) $row['rounding_adjustment']->minor, $rows)))->toBe(0);
})->with([
    'single fee-bearing receipt' => ['950', '2026-12-31=1100', '0.157894736842105263'],
    'level payments rejected by the old neighbour search' => ['95431.11', '2026-12-31=42317.33 2027-12-31=42317.33 2028-12-31=31424.78', '0.110100006680210465'],
    'zero rate' => ['300', '2026-12-31=100 2027-12-31=100 2028-12-31=100', '0.000000000000000000'],
]);

it('rejects effective rates outside zero to one and plans that no rounded rate conserves', function (string $cost, string $payments, string $message) {
    expect(fn () => (new FinancialCalculator)->effectiveRate(Money::of($cost, Currency::iso('KES')), ratePlan($payments)))->toThrow(LedgerException::class, $message);
})->with([
    'negative rate' => ['300', '2026-12-31=299.99', 'outside the supported zero-to-one range'],
    'rate above one' => ['100', '2026-12-31=200.01', 'outside the supported zero-to-one range'],
    'no conserving rounding' => ['0.01', '2026-12-31=0.01 2027-12-31=0.01', 'No bounded effective rate conserves'],
]);

it('measures six hundred payment slots quickly and caps plans at the database bound', function () {
    $kes = Currency::iso('KES');
    $plan = [];
    for ($slot = 1; $slot <= 600; $slot++) {
        $plan[] = ['due_on' => sprintf('%04d-12-31', 2025 + $slot), 'payment' => Money::of($slot === 600 ? '1008.33' : '8.33', $kes)];
    }
    $started = microtime(true);
    $rate = (new FinancialCalculator)->effectiveRate(Money::of('1000', $kes), $plan);
    expect(microtime(true) - $started)->toBeLessThan(5.0)
        ->and(fn () => (new FinancialCalculator)->effectiveRate(Money::of('1000', $kes), [...$plan, ['due_on' => '2626-12-31', 'payment' => Money::of('1', $kes)]]))
        ->toThrow(LedgerException::class, 'between one and 600');
    $rows = (new FinancialCalculator)->effectiveInterest(Money::of('1000', $kes), $rate, $plan);
    expect($rows[599]['closing']->decimal())->toBe('0.00');
});
