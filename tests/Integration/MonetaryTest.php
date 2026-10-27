<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use NoriaLabs\Ledger\Access\Permission;
use NoriaLabs\Ledger\Accounting\AccountType;
use NoriaLabs\Ledger\Accounting\JournalRequest;
use NoriaLabs\Ledger\Accounting\Posting;
use NoriaLabs\Ledger\Accounting\Side;
use NoriaLabs\Ledger\Money\Currency;
use NoriaLabs\Ledger\Money\ExchangeRate;
use NoriaLabs\Ledger\Money\Money;
use NoriaLabs\Ledger\Money\Rounding;

beforeEach(function () {
    $this->usd = Currency::iso('USD');
    $this->foreignBank = $this->ledger->catalog->account($this->book, '1001', 'USD bank', AccountType::Asset, $this->usd, cashFlow: 'cash');
    $this->capital = $this->ledger->catalog->account($this->book, '3000', 'Capital', AccountType::Equity, cashFlow: 'financing');
    $this->gain = $this->ledger->catalog->account($this->book, '4100', 'Exchange gains', AccountType::Income);
    $this->loss = $this->ledger->catalog->account($this->book, '5100', 'Exchange losses', AccountType::Expense);
    $this->initialRate = new ExchangeRate($this->usd, $this->kes, '130', '2026-01-01', 'original', Rounding::HalfEven);
    $this->closingRate = new ExchangeRate($this->usd, $this->kes, '140', '2026-01-31', 'closing', Rounding::HalfEven);
    $this->ledger->monetaryAccounts->register($this->book, $this->foreignBank, 'monetary-cash:v1');
});

it('revalues a foreign bank without changing its currency quantity and classifies the exchange effect', function () {
    $amount = Money::of('100', $this->usd);
    $this->ledger->journals->post(new JournalRequest($this->book, 'fund', '2026-01-01', 'Fund USD bank', [
        new Posting($this->foreignBank, Side::Debit, $amount, $this->initialRate), new Posting($this->capital, Side::Credit, $this->initialRate->convert($amount)),
    ]));
    $before = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $valuation = $this->ledger->monetaryAccounts->revalue($this->book, $this->foreignBank, 'january', '2026-01-31', $this->closingRate, $this->gain, $this->loss, 'January closing');
    expect($valuation->sourceBalance->decimal())->toBe('100.00');
    expect($valuation->carryingBefore->decimal())->toBe('13000.00');
    expect($valuation->adjustment->decimal())->toBe('1000.00');
    expect($valuation->carryingAfter()->decimal())->toBe('14000.00');
    expect($this->ledger->monetaryAccounts->revalue($this->book, $this->foreignBank, 'january', '2026-01-31', $this->closingRate, $this->gain, $this->loss, 'January closing')->id)->toBe($valuation->id);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $flow = $this->ledger->reports->cashFlow($snapshot, '2026-01-01');
    expect($flow->financing->decimal())->toBe('13000.00');
    expect($flow->exchange->decimal())->toBe('1000.00');
    expect($flow->closing->decimal())->toBe('14000.00');
    expect($flow->isFullyClassified())->toBeTrue();
    expect($this->ledger->reports->cashFlow($before, '2026-01-01')->closing->decimal())->toBe('13000.00');
    $noChange = $this->ledger->monetaryAccounts->revalue($this->book, $this->foreignBank, 'no-change', '2026-01-31', $this->closingRate, $this->gain, $this->loss, 'Same rate');
    expect($noChange->journalId)->toBeNull();
    $this->ledger->monetaryAccounts->reverse($this->book, $noChange->id, 'reverse-no-change', '2026-02-01', 'Correct valuation request');
    $this->ledger->monetaryAccounts->reverse($this->book, $valuation->id, 'reverse-january', '2026-02-01', 'Reverse January valuation');
    $flow = $this->ledger->reports->cashFlow($this->ledger->reports->snapshot($this->book, '2026-02-28'), '2026-01-01');
    expect($flow->exchange->decimal())->toBe('0.00');
    expect($flow->closing->decimal())->toBe('13000.00');
    $this->storage->run(Permission::Read, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE'), $this->book);
});

it('handles foreign loan credit balances as exchange losses when their functional value increases', function () {
    $loan = $this->ledger->catalog->account($this->book, '2100', 'USD loan', AccountType::Liability, $this->usd);
    $this->ledger->monetaryAccounts->register($this->book, $loan, 'monetary-loan:v1');
    $amount = Money::of('100', $this->usd);
    $this->ledger->journals->post(new JournalRequest($this->book, 'loan', '2026-01-01', 'Loan proceeds', [
        new Posting($this->cash, Side::Debit, $this->initialRate->convert($amount)), new Posting($loan, Side::Credit, $amount, $this->initialRate),
    ]));
    $valuation = $this->ledger->monetaryAccounts->revalue($this->book, $loan, 'loan-close', '2026-01-31', $this->closingRate, $this->gain, $this->loss, 'Close loan balance');
    expect($valuation->sourceBalance->decimal())->toBe('-100.00');
    expect($valuation->adjustment->decimal())->toBe('-1000.00');
    expect($valuation->carryingAfter()->decimal())->toBe('-14000.00');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    expect($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['expenses']->decimal())->toBe('1000.00');
    expect($this->ledger->reports->balanceSheet($snapshot)['difference']->decimal())->toBe('0.00');
    $this->storage->run(Permission::Read, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE'), $this->book);
});

it('clears a functional remainder when the foreign-currency balance is zero', function () {
    $amount = Money::of('100', $this->usd);
    $this->ledger->journals->post(new JournalRequest($this->book, 'fund', '2026-01-01', 'Fund', [
        new Posting($this->foreignBank, Side::Debit, $amount, $this->initialRate), new Posting($this->capital, Side::Credit, $this->initialRate->convert($amount)),
    ]));
    $exit = new ExchangeRate($this->usd, $this->kes, '145', '2026-01-15', 'conversion', Rounding::HalfEven);
    $this->ledger->journals->post(new JournalRequest($this->book, 'convert', '2026-01-15', 'Convert all dollars to shillings', [
        new Posting($this->cash, Side::Debit, $exit->convert($amount)), new Posting($this->foreignBank, Side::Credit, $amount, $exit),
    ]));
    $valuation = $this->ledger->monetaryAccounts->revalue($this->book, $this->foreignBank, 'zero-source', '2026-01-31', $this->closingRate, $this->gain, $this->loss, 'Clear conversion remainder');
    expect($valuation->sourceBalance->decimal())->toBe('0.00');
    expect($valuation->carryingBefore->decimal())->toBe('-1500.00');
    expect($valuation->adjustment->decimal())->toBe('1500.00');
    expect($valuation->carryingAfter()->decimal())->toBe('0.00');
});

it('can close an earlier date after an ordinary future-dated transaction has been recorded', function () {
    $amount = Money::of('100', $this->usd);
    foreach (['2026-01-01', '2026-02-01'] as $date) {
        $this->ledger->journals->post(new JournalRequest($this->book, $date, $date, 'Fund bank', [
            new Posting($this->foreignBank, Side::Debit, $amount, $this->initialRate), new Posting($this->capital, Side::Credit, $this->initialRate->convert($amount)),
        ]));
    }
    $valuation = $this->ledger->monetaryAccounts->revalue($this->book, $this->foreignBank, 'january', '2026-01-31', $this->closingRate, $this->gain, $this->loss, 'January only');
    expect($valuation->sourceBalance->decimal())->toBe('100.00');
    expect($valuation->carryingAfter()->decimal())->toBe('14000.00');
    $this->storage->run(Permission::Read, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE'), $this->book);
});

it('rejects journal-only valuation adjustments on a registered account at commit', function () {
    $this->ledger->journals->post(new JournalRequest($this->book, 'orphan', '2026-01-31', 'Detached valuation', [
        new Posting($this->foreignBank, Side::Debit, Money::of('0', $this->usd), $this->closingRate, valuation: Money::of('1', $this->kes)),
        new Posting($this->gain, Side::Credit, Money::of('1', $this->kes)),
    ], ['cash_flow' => ['exchange' => '100']]));
    $this->storage->run(Permission::Read, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE'), $this->book);
})->throws(QueryException::class, 'corresponding valuation evidence');

it('rejects backdating before a valuation while allowing other dimension buckets', function () {
    $this->ledger->catalog->dimensionValue($this->entity, 'branch', 'A');
    $this->ledger->catalog->dimensionValue($this->entity, 'branch', 'B');
    $this->ledger->monetaryAccounts->revalue($this->book, $this->foreignBank, 'zero-close', '2026-01-31', $this->closingRate, $this->gain, $this->loss, 'Close branch A', ['branch' => 'A']);
    $amount = Money::of('1', $this->usd);
    $this->ledger->journals->post(new JournalRequest($this->book, 'branch-b', '2026-01-01', 'Other branch', [
        new Posting($this->foreignBank, Side::Debit, $amount, $this->initialRate, dimensions: ['branch' => 'B']),
        new Posting($this->capital, Side::Credit, $this->initialRate->convert($amount), dimensions: ['branch' => 'B']),
    ]));
    $this->storage->run(Permission::Read, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE'), $this->book);
    $this->ledger->journals->post(new JournalRequest($this->book, 'branch-a', '2026-01-01', 'Invalid backdate', [
        new Posting($this->foreignBank, Side::Debit, $amount, $this->initialRate, dimensions: ['branch' => 'A']),
        new Posting($this->capital, Side::Credit, $this->initialRate->convert($amount), dimensions: ['branch' => 'A']),
    ]));
})->throws(QueryException::class, 'silently backdate');

it('keeps control accounts on the open-item revaluation path', function () {
    $ar = $this->ledger->catalog->account($this->book, '1100', 'USD receivables', AccountType::Asset, $this->usd, control: true);
    $this->ledger->monetaryAccounts->register($this->book, $ar, 'invalid');
})->throws(QueryException::class, 'foreign non-control');

it('enforces valuation linkage when constraints are immediate before the journal insert', function () {
    $this->storage->run(Permission::Read, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE'), $this->book);
    $this->ledger->journals->post(new JournalRequest($this->book, 'immediate-orphan', '2026-01-31', 'Detached valuation', [
        new Posting($this->foreignBank, Side::Debit, Money::of('0', $this->usd), $this->closingRate, valuation: Money::of('1', $this->kes)),
        new Posting($this->gain, Side::Credit, Money::of('1', $this->kes)),
    ], ['cash_flow' => ['exchange' => '100']]));
})->throws(QueryException::class, 'corresponding valuation evidence');
