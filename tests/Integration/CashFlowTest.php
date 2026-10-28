<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Money\Rounding;

it('classifies simple cash activity and reconciles it to cash balances', function () {
    $money = Money::of('100', $this->kes);
    $this->ledger->journals->post(new JournalRequest($this->book, 'cash-income', '2026-01-01', 'Income', [new Posting($this->cash, Side::Debit, $money), new Posting($this->income, Side::Credit, $money)]));
    $flow = $this->ledger->reports->cashFlow($this->ledger->reports->snapshot($this->book, '2026-01-31'), '2026-01-01');
    expect($flow->opening->decimal())->toBe('0.00');
    expect($flow->closing->decimal())->toBe('100.00');
    expect($flow->operating->decimal())->toBe('100.00');
    expect($flow->isFullyClassified())->toBeTrue();
});

it('flags ambiguous compound entries and accepts explicit balanced classification', function () {
    $loan = $this->ledger->catalog->account($this->book, '2100', 'Loan', AccountType::Liability, cashFlow: 'financing');
    $equipment = $this->ledger->catalog->account($this->book, '1500', 'Equipment', AccountType::Asset, cashFlow: 'investing');
    $lines = [new Posting($this->cash, Side::Debit, Money::of('10', $this->kes)), new Posting($equipment, Side::Debit, Money::of('90', $this->kes)), new Posting($loan, Side::Credit, Money::of('100', $this->kes))];
    $this->ledger->journals->post(new JournalRequest($this->book, 'unclassified', '2026-01-01', 'Partly noncash acquisition', $lines));
    $flow = $this->ledger->reports->cashFlow($this->ledger->reports->snapshot($this->book, '2026-01-31'), '2026-01-01');
    expect($flow->isFullyClassified())->toBeFalse();
    expect($flow->unclassified->decimal())->toBe('10.00');
    $journal = $this->ledger->journals->post(new JournalRequest($this->book, 'classified', '2026-02-01', 'Partly noncash acquisition', $lines, ['cash_flow' => ['financing' => '1000']]));
    $flow = $this->ledger->reports->cashFlow($this->ledger->reports->snapshot($this->book, '2026-02-28'), '2026-02-01');
    expect($flow->financing->decimal())->toBe('10.00');
    expect($flow->isFullyClassified())->toBeTrue();
    $this->ledger->journals->reverse($this->book, $journal->id, 'reverse-classified', '2026-02-02', 'Cancel acquisition');
    $flow = $this->ledger->reports->cashFlow($this->ledger->reports->snapshot($this->book, '2026-02-28'), '2026-02-01');
    expect($flow->financing->decimal())->toBe('0.00');
    expect($flow->isFullyClassified())->toBeTrue();
});

it('rejects cash-flow allocations that do not equal actual cash movement', function () {
    $money = Money::of('100', $this->kes);
    $this->ledger->journals->post(new JournalRequest($this->book, 'invalid-cash-flow', '2026-01-01', 'Income', [new Posting($this->cash, Side::Debit, $money), new Posting($this->income, Side::Credit, $money)], ['cash_flow' => ['operating' => '9999']]));
})->throws(QueryException::class, 'must equal the actual cash movement');

it('nets internal cash transfers to nothing and reports foreign cash valuations as exchange effects', function () {
    $usd = new Currency('USD', 2);
    $savings = $this->ledger->catalog->account($this->book, '1010', 'Savings', AccountType::Asset, cashFlow: 'cash');
    $usdBank = $this->ledger->catalog->account($this->book, '1020', 'USD bank', AccountType::Asset, $usd, cashFlow: 'cash');
    $gain = $this->ledger->catalog->account($this->book, '4100', 'Exchange gains', AccountType::Income);
    $money = Money::of('100', $this->kes);
    $this->ledger->journals->post(new JournalRequest($this->book, 'transfer', '2026-01-02', 'Sweep to savings', [new Posting($savings, Side::Debit, $money), new Posting($this->cash, Side::Credit, $money)]));
    $rate = new ExchangeRate($usd, $this->kes, '140', '2026-01-31', 'treasury', Rounding::HalfEven);
    $this->ledger->journals->post(new JournalRequest($this->book, 'usd-valuation', '2026-01-31', 'USD bank valuation', [
        new Posting($usdBank, Side::Debit, Money::of('0', $usd), $rate, valuation: Money::of('50', $this->kes)), new Posting($gain, Side::Credit, Money::of('50', $this->kes)),
    ]));
    $flow = $this->ledger->reports->cashFlow($this->ledger->reports->snapshot($this->book, '2026-01-31'), '2026-01-01');
    expect($flow->exchange->decimal())->toBe('50.00')
        ->and($flow->operating->decimal())->toBe('0.00')
        ->and($flow->closing->decimal())->toBe('50.00')
        ->and($flow->isFullyClassified())->toBeTrue();
});
