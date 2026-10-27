<?php

declare(strict_types=1);

use Brick\Math\BigRational;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Access\Scope;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\AllocationRequest;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Ledger;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Money\Rounding;
use Noria\Ledger\Tests\Support\Database;
use Noria\Ledger\Tests\Support\PermitAll;
use Random\Engine\Mt19937;
use Random\Randomizer;

it('agrees with arbitrary-precision rational arithmetic at rounding boundaries', function () {
    $random = new Randomizer(new Mt19937(713));
    $pairs = [['99999999999999999999999999999999999999', '2'], ['99999999999999999999999999999999999998', '2'],
        ['10000000000000000000000000000000000001', '20000000000000000000000000000000000001']];

    for ($i = 0; $i < 100; $i++) {
        $pairs[] = [(string) $random->getInt(-1000000, 1000000), (string) $random->getInt(1, 10000)];
    }

    foreach ($pairs as [$numerator, $denominator]) {
        foreach (Rounding::cases() as $rounding) {
            $expected = (string) BigRational::of($numerator)->dividedBy($denominator)->toScale(0, $rounding->brick());
            $row = $this->app['db']->connection()->selectOne('SELECT ledger_fraction(?::numeric, ?::numeric, ?)::text AS result', [$numerator, $denominator, $rounding->value]);
            expect($row->result)->toBe($expected);
        }
    }
});

it('preserves carrying-value remainders after releasing earlier zero-cent allocations', function () {
    $usd = Currency::iso('USD');
    $ar = $this->ledger->catalog->account($this->book, '1101', 'Foreign receivables', AccountType::Asset, $usd, control: true);
    $party = $this->ledger->catalog->party($this->entity, 'customer', 'Customer');
    $rate = new ExchangeRate($usd, $this->kes, '0.3', '2026-01-01', 'precision-fixture:v1', Rounding::HalfEven);
    $amount = Money::of('0.10', $usd);
    $this->ledger->journals->post(new JournalRequest($this->book, 'invoice', '2026-01-01', 'Invoice', [new Posting($ar, Side::Debit, $amount, $rate, $party), new Posting($this->income, Side::Credit, $rate->convert($amount))]));
    $this->ledger->journals->post(new JournalRequest($this->book, 'payment', '2026-01-02', 'Receipt', [new Posting($this->cash, Side::Debit, $rate->convert($amount)), new Posting($ar, Side::Credit, $amount, $rate, $party)]));
    $items = $this->ledger->reports->openItems($this->ledger->reports->snapshot($this->book, '2026-02-28'));
    $allocations = [];

    for ($i = 0; $i < 8; $i++) {
        $allocations[] = $this->ledger->settlements->allocate(new AllocationRequest($this->book, 'allocation-'.$i, $items[0]->id, $items[1]->id, Money::of('0.01', $usd), '2026-02-01', 'Partial allocation'));
    }

    foreach (array_slice($allocations, 0, 5) as $index => $id) {
        $this->ledger->settlements->release($this->book, $id, 'release-'.$index, '2026-02-02', 'Release earlier allocation');
    }

    $this->ledger->settlements->allocate(new AllocationRequest($this->book, 'replacement', $items[0]->id, $items[1]->id, Money::of('0.01', $usd), '2026-02-03', 'Allocate remaining carrying value'));
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-02-28');
    $remaining = $this->ledger->reports->openItems($snapshot);
    expect($remaining[0]->outstanding->decimal())->toBe('0.06');
    expect($remaining[0]->functionalOutstanding->decimal())->toBe('0.01');
    expect($this->ledger->reports->controlReconciliation($snapshot, $ar)->decimal())->toBe('0.00');
});

it('rejects untracked control-account valuations when a real transaction commits', function () {
    $connection = Database::connection();
    $ledger = (new Ledger($connection, new PermitAll))->for(new Scope((string) Str::uuid7(), 'commit-test'));
    $entity = $ledger->catalog->entity('Commit verification');
    $book = $ledger->catalog->book($entity, 'Statutory', $this->kes);
    $ledger->periods->open($book, '2026', '2026-01-01', '2026-12-31');
    $party = $ledger->catalog->party($entity, 'customer', 'Customer');
    $usd = Currency::iso('USD');
    $ar = $ledger->catalog->account($book, '1100', 'USD receivables', AccountType::Asset, $usd, control: true);
    $gain = $ledger->catalog->account($book, '4000', 'FX gain', AccountType::Income);
    $rate = new ExchangeRate($usd, $this->kes, '130', '2026-01-01', 'test:v1', Rounding::HalfEven);

    try {
        $ledger->journals->post(new JournalRequest($book, 'untracked-valuation', '2026-01-01', 'Invalid carrying-value adjustment', [
            new Posting($ar, Side::Debit, Money::of('0', $usd), $rate, $party, valuation: Money::of('100', $this->kes)),
            new Posting($gain, Side::Credit, Money::of('100', $this->kes)),
        ]));
        test()->fail('An untracked control valuation must fail at commit.');
    } catch (PDOException $exception) {
        expect($exception->getMessage())->toContain('corresponding settlement');
    }

    expect($ledger->reports->snapshot($book, '2026-12-31')->throughSequence)->toBe('0');
});

it('checks registered ISO currency scales independently in PostgreSQL', function () {
    $this->storage->run(Permission::Configure, function (): void {
        $this->storage->insert('ledger_currencies', ['code' => 'JPY', 'scale' => 2]);
    });
})->throws(QueryException::class, 'ISO scales must match');

it('does not bypass control-valuation linkage when constraints are already immediate', function () {
    $usd = Currency::iso('USD');
    $party = $this->ledger->catalog->party($this->entity, 'customer', 'Customer');
    $ar = $this->ledger->catalog->account($this->book, '1100', 'USD receivables', AccountType::Asset, $usd, control: true);
    $rate = new ExchangeRate($usd, $this->kes, '130', '2026-01-01', 'test', Rounding::HalfEven);
    $this->storage->run(Permission::Read, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE'), $this->book);
    $this->ledger->journals->post(new JournalRequest($this->book, 'orphan', '2026-01-01', 'Untracked valuation', [
        new Posting($ar, Side::Debit, Money::of('0', $usd), $rate, $party, valuation: Money::of('1', $this->kes)),
        new Posting($this->income, Side::Credit, Money::of('1', $this->kes)),
    ]));
})->throws(QueryException::class, 'corresponding settlement');

it('commits and releases linked realized FX journals under row-level security', function (string $settlementRate, string $expectedGain, string $expectedLoss) {
    $connection = Database::connection();
    $ledger = (new Ledger($connection, new PermitAll))->for(new Scope((string) Str::uuid7(), 'committed-fx-test'));
    $entity = $ledger->catalog->entity('Committed FX book');
    $book = $ledger->catalog->book($entity, 'Statutory', $this->kes);
    $ledger->periods->open($book, '2026', '2026-01-01', '2026-12-31');
    $party = $ledger->catalog->party($entity, 'customer', 'Customer');
    $usd = Currency::iso('USD');
    $ar = $ledger->catalog->account($book, '1100', 'USD receivables', AccountType::Asset, $usd, control: true);
    $bank = $ledger->catalog->account($book, '1000', 'Bank', AccountType::Asset);
    $income = $ledger->catalog->account($book, '4000', 'Income', AccountType::Income);
    $gain = $ledger->catalog->account($book, '4100', 'Exchange gain', AccountType::Income);
    $loss = $ledger->catalog->account($book, '5100', 'Exchange loss', AccountType::Expense);
    $invoiceRate = new ExchangeRate($usd, $this->kes, '130', '2026-01-01', 'invoice-rate:v1', Rounding::HalfEven);
    $paymentRate = new ExchangeRate($usd, $this->kes, $settlementRate, '2026-02-01', 'settlement-rate:v1', Rounding::HalfEven);
    $amount = Money::of('100', $usd);
    $ledger->journals->post(new JournalRequest($book, 'invoice', '2026-01-01', 'Invoice', [new Posting($ar, Side::Debit, $amount, $invoiceRate, $party), new Posting($income, Side::Credit, $invoiceRate->convert($amount))]));
    $ledger->journals->post(new JournalRequest($book, 'receipt', '2026-02-01', 'Receipt', [new Posting($bank, Side::Debit, $paymentRate->convert($amount)), new Posting($ar, Side::Credit, $amount, $paymentRate, $party)]));
    $items = $ledger->reports->openItems($ledger->reports->snapshot($book, '2026-02-28'));
    $allocation = $ledger->settlements->allocate(new AllocationRequest($book, 'allocate-fx', $items[0]->id, $items[1]->id, $amount, '2026-02-02', 'Settle foreign invoice', $gain, $loss));
    $snapshot = $ledger->reports->snapshot($book, '2026-02-28');
    $balances = collect($ledger->reports->trialBalance($snapshot))->keyBy('accountId');
    expect($ledger->reports->openItems($snapshot))->toBe([]);
    expect($ledger->reports->controlReconciliation($snapshot, $ar)->decimal())->toBe('0.00');
    expect($balances[$gain]->closing->decimal())->toBe($expectedGain);
    expect($balances[$loss]->closing->decimal())->toBe($expectedLoss);
    $ledger->settlements->release($book, $allocation, 'release-fx', '2026-02-03', 'Release settlement');
    $snapshot = $ledger->reports->snapshot($book, '2026-02-28');
    expect($ledger->reports->openItems($snapshot))->toHaveCount(2);
    expect($ledger->reports->controlReconciliation($snapshot, $ar)->decimal())->toBe('0.00');
})->with([['135', '-500.00', '0.00'], ['125', '0.00', '500.00']]);
