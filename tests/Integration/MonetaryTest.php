<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Money\Rounding;

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

it('rejects zero-adjustment valuation evidence for inactive monetary accounts', function () {
    $this->ledger->catalog->setAccountActive($this->book, $this->foreignBank, false);
    $this->ledger->monetaryAccounts->revalue($this->book, $this->foreignBank, 'inactive-zero', '2026-01-31', $this->closingRate, $this->gain, $this->loss, 'No activity');
})->throws(QueryException::class, 'inactive or soft-deleted monetary account');

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
    $reversal = $this->ledger->monetaryAccounts->reverse($this->book, $valuation->id, 'reverse-january', '2026-02-01', 'Reverse January valuation');
    expect($this->ledger->monetaryAccounts->reverse($this->book, $valuation->id, 'reverse-january', '2026-02-01', 'Reverse January valuation')->id)->toBe($reversal->id)
        ->and($this->ledger->monetaryAccounts->register($this->book, $this->foreignBank, 'monetary-cash:v1'))->toBeString()
        ->and(fn () => $this->ledger->monetaryAccounts->register($this->book, $this->foreignBank, 'monetary-cash:v2'))->toThrow(LedgerException::class, 'cannot change its eligibility policy');
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

it('realizes exchange on each foreign outflow at the carrying share so the remaining balance keeps its original rate', function () {
    $supplies = $this->ledger->catalog->account($this->book, '5200', 'Supplies', AccountType::Expense, cashFlow: 'operating');
    $fund = Money::of('100', $this->usd);
    $this->ledger->journals->post(new JournalRequest($this->book, 'fund', '2026-01-01', 'Fund USD bank', [
        new Posting($this->foreignBank, Side::Debit, $fund, $this->initialRate), new Posting($this->capital, Side::Credit, $this->initialRate->convert($fund)),
    ]));
    $spot = new ExchangeRate($this->usd, $this->kes, '140', '2026-01-10', 'spot', Rounding::HalfEven);
    $spend = Money::of('40', $this->usd);
    $result = $this->ledger->monetaryAccounts->postRealized(new JournalRequest($this->book, 'spend', '2026-01-10', 'Buy supplies', [
        new Posting($supplies, Side::Debit, $spot->convert($spend)), new Posting($this->foreignBank, Side::Credit, $spend, $spot),
    ]), $this->gain, $this->loss);

    $balances = byAccount($this->ledger->reports->trialBalance($this->ledger->reports->snapshot($this->book, '2026-01-10')));
    $cashFlow = $this->ledger->reports->cashFlow($this->ledger->reports->snapshot($this->book, '2026-01-10'), '2026-01-01');
    expect($result['valuations'])->toHaveCount(1)
        ->and($result['valuations'][0]->adjustment->decimal())->toBe('400.00')
        ->and($balances[$this->foreignBank]->closing->decimal())->toBe('7800.00')
        ->and($balances[$this->gain]->closing->decimal())->toBe('-400.00')
        ->and($balances[$supplies]->closing->decimal())->toBe('5600.00')
        ->and($cashFlow->exchange->decimal())->toBe('400.00')
        ->and($cashFlow->operating->decimal())->toBe('-5600.00')
        ->and($this->ledger->monetaryAccounts->realize($this->book, $result['journal']->id, $this->foreignBank, $this->gain, $this->loss)?->id)->toBe($result['valuations'][0]->id);

    $inflow = $this->ledger->journals->post(new JournalRequest($this->book, 'top-up', '2026-01-11', 'Top up', [
        new Posting($this->foreignBank, Side::Debit, $spend, $spot), new Posting($this->capital, Side::Credit, $spot->convert($spend)),
    ]));
    expect($this->ledger->monetaryAccounts->realize($this->book, $inflow->id, $this->foreignBank, $this->gain, $this->loss))->toBeNull();
});

it('conserves carrying value to zero when tiny outflows drain a foreign balance', function () {
    $supplies = $this->ledger->catalog->account($this->book, '5200', 'Supplies', AccountType::Expense);
    $rate = new ExchangeRate($this->usd, $this->kes, '1.5', '2026-01-01', 'test', Rounding::HalfEven);
    $three = Money::of('0.03', $this->usd);
    $this->ledger->journals->post(new JournalRequest($this->book, 'fund', '2026-01-01', 'Fund', [
        new Posting($this->foreignBank, Side::Debit, $three, $rate), new Posting($this->capital, Side::Credit, $rate->convert($three)),
    ]));
    foreach (['a', 'b', 'c'] as $key) {
        $cent = Money::of('0.01', $this->usd);
        $this->ledger->monetaryAccounts->postRealized(new JournalRequest($this->book, $key, '2026-01-02', 'Spend', [
            new Posting($supplies, Side::Debit, $rate->convert($cent)), new Posting($this->foreignBank, Side::Credit, $cent, $rate),
        ]), $this->gain, $this->loss);
    }
    $balances = byAccount($this->ledger->reports->trialBalance($this->ledger->reports->snapshot($this->book, '2026-01-02')));
    expect($balances[$this->foreignBank]->closing->decimal())->toBe('0.00')
        ->and($balances[$supplies]->closing->minus($balances[$this->gain]->closing->negated())->plus($balances[$this->loss]->closing)->decimal())->toBe('0.04');
});

it('requires realization before later activity and before reversing the outflow', function () {
    $supplies = $this->ledger->catalog->account($this->book, '5200', 'Supplies', AccountType::Expense);
    $fund = Money::of('200', $this->usd);
    $this->ledger->journals->post(new JournalRequest($this->book, 'fund', '2026-01-01', 'Fund', [
        new Posting($this->foreignBank, Side::Debit, $fund, $this->initialRate), new Posting($this->capital, Side::Credit, $this->initialRate->convert($fund)),
    ]));
    $spot = new ExchangeRate($this->usd, $this->kes, '140', '2026-01-10', 'spot', Rounding::HalfEven);
    $spend = Money::of('40', $this->usd);
    $request = fn (string $key, string $date) => new JournalRequest($this->book, $key, $date, 'Spend', [
        new Posting($supplies, Side::Debit, $spot->convert($spend)), new Posting($this->foreignBank, Side::Credit, $spend, $spot),
    ]);
    $late = $this->ledger->journals->post($request('unrealized', '2026-01-10'));
    $this->ledger->journals->post($request('later', '2026-01-12'));
    expect(fn () => $this->ledger->monetaryAccounts->realize($this->book, $late->id, $this->foreignBank, $this->gain, $this->loss))->toThrow(QueryException::class, 'before later activity');

    $result = $this->ledger->monetaryAccounts->postRealized($request('realized', '2026-01-13'), $this->gain, $this->loss);
    $this->storage->run(Permission::Read, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE'), $this->book);
    expect(fn () => $this->ledger->journals->reverse($this->book, $result['journal']->id, 'reverse-spend', '2026-01-14', 'Wrong'))->toThrow(QueryException::class, 'realized exchange valuation before');
    $this->storage->run(Permission::Read, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL DEFERRED'), $this->book);
    $this->ledger->monetaryAccounts->reverse($this->book, $result['valuations'][0]->id, 'reverse-realized', '2026-01-14', 'Wrong');
    expect($this->ledger->journals->reverse($this->book, $result['journal']->id, 'reverse-spend-2', '2026-01-14', 'Wrong')->reversesId)->toBe($result['journal']->id);
});
