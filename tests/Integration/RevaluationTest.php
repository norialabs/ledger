<?php

declare(strict_types=1);

use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\AllocationRequest;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Money\Rounding;

it('revalues the remaining foreign obligation and reclassifies gains when settled', function () {
    $usd = Currency::iso('USD');
    $ar = $this->ledger->catalog->account($this->book, '1100', 'USD receivables', AccountType::Asset, $usd, control: true);
    $party = $this->ledger->catalog->party($this->entity, 'customer', 'Customer');
    $unrealized = $this->ledger->catalog->account($this->book, '4100', 'Unrealized FX', AccountType::Income);
    $realized = $this->ledger->catalog->account($this->book, '4200', 'Realized FX', AccountType::Income);
    $loss = $this->ledger->catalog->account($this->book, '5100', 'FX losses', AccountType::Expense);
    $amount = Money::of('100', $usd);
    $initial = new ExchangeRate($usd, $this->kes, '130', '2026-01-01', 'treasury:initial', Rounding::HalfEven);
    $closing = new ExchangeRate($usd, $this->kes, '140', '2026-01-31', 'treasury:closing', Rounding::HalfEven);
    $paid = new ExchangeRate($usd, $this->kes, '145', '2026-02-01', 'treasury:payment', Rounding::HalfEven);
    $this->ledger->journals->post(new JournalRequest($this->book, 'invoice', '2026-01-01', 'Invoice', [new Posting($ar, Side::Debit, $amount, $initial, $party), new Posting($this->income, Side::Credit, $initial->convert($amount))]));
    $old = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $item = $this->ledger->reports->openItems($old)[0];
    $journal = $this->ledger->revaluations->revalue($this->book, $item->id, 'revalue-january', '2026-01-31', $closing, $unrealized, $loss, 'Month-end revaluation');
    expect($this->ledger->revaluations->revalue($this->book, $item->id, 'revalue-january', '2026-01-31', $closing, $unrealized, $loss, 'Month-end revaluation')->id)->toBe($journal->id);
    $monthEnd = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    expect($this->ledger->reports->openItems($monthEnd)[0]->functionalOutstanding->decimal())->toBe('14000.00');
    expect($this->ledger->reports->openItems($old)[0]->functionalOutstanding->decimal())->toBe('13000.00');
    expect($this->ledger->reports->controlReconciliation($monthEnd, $ar)->decimal())->toBe('0.00');
    $this->ledger->journals->post(new JournalRequest($this->book, 'receipt', '2026-02-01', 'Receipt', [new Posting($this->cash, Side::Debit, $paid->convert($amount)), new Posting($ar, Side::Credit, $amount, $paid, $party)]));
    $items = $this->ledger->reports->openItems($this->ledger->reports->snapshot($this->book, '2026-02-28'));
    $this->ledger->settlements->allocate(new AllocationRequest($this->book, 'settle', $items[0]->id, $items[1]->id, $amount, '2026-02-02', 'Settle invoice', $realized, $loss));
    $final = $this->ledger->reports->snapshot($this->book, '2026-02-28');
    $balances = collect($this->ledger->reports->trialBalance($final))->keyBy('accountId');
    expect($this->ledger->reports->openItems($final))->toBe([]);
    expect($balances[$unrealized]->closing->decimal())->toBe('0.00');
    expect($balances[$realized]->closing->decimal())->toBe('-1500.00');
    expect($this->ledger->reports->controlReconciliation($final, $ar)->decimal())->toBe('0.00');
    $this->storage->run(Permission::Read, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE'), $this->book);
});

it('revalues only the unpaid balance and releases partial settlements exactly', function (Side $side) {
    $usd = Currency::iso('USD');
    $control = $this->ledger->catalog->account($this->book, '1100', 'Foreign control', $side === Side::Debit ? AccountType::Asset : AccountType::Liability, $usd, control: true);
    $party = $this->ledger->catalog->party($this->entity, 'counterparty', 'Counterparty');
    $counter = $side === Side::Debit ? $this->income : $this->ledger->catalog->account($this->book, '5000', 'Purchases', AccountType::Expense);
    $unrealizedGain = $this->ledger->catalog->account($this->book, '4100', 'Unrealized gain', AccountType::Income);
    $unrealizedLoss = $this->ledger->catalog->account($this->book, '5100', 'Unrealized loss', AccountType::Expense);
    $realizedGain = $this->ledger->catalog->account($this->book, '4200', 'Realized gain', AccountType::Income);
    $realizedLoss = $this->ledger->catalog->account($this->book, '5200', 'Realized loss', AccountType::Expense);
    $rate = new ExchangeRate($usd, $this->kes, '130', '2026-01-01', 'original', Rounding::HalfEven);
    $amount = Money::of('100', $usd);
    $this->ledger->journals->post(new JournalRequest($this->book, 'invoice', '2026-01-01', 'Foreign invoice', [
        new Posting($control, $side, $amount, $rate, $party), new Posting($counter, $side === Side::Debit ? Side::Credit : Side::Debit, $rate->convert($amount)),
    ]));
    $original = $this->ledger->reports->openItems($this->ledger->reports->snapshot($this->book, '2026-01-31'))[0];
    expect($this->ledger->revaluations->revalue($this->book, $original->id, 'no-change', '2026-01-02', $rate, $unrealizedGain, $unrealizedLoss, 'No rate change'))->toBeNull();
    expect($this->ledger->revaluations->revalue($this->book, $original->id, 'no-change', '2026-01-02', $rate, $unrealizedGain, $unrealizedLoss, 'No rate change'))->toBeNull();
    $closing = new ExchangeRate($usd, $this->kes, '140', '2026-01-31', 'closing', Rounding::HalfEven);
    $this->ledger->revaluations->revalue($this->book, $original->id, 'closing', '2026-01-31', $closing, $unrealizedGain, $unrealizedLoss, 'Closing revaluation');
    $paidRate = new ExchangeRate($usd, $this->kes, '145', '2026-02-01', 'paid', Rounding::HalfEven);
    $partial = Money::of('40', $usd);
    $payment = $this->ledger->journals->post(new JournalRequest($this->book, 'payment', '2026-02-01', 'Partial payment', [
        new Posting($this->cash, $side, $paidRate->convert($partial)), new Posting($control, $side === Side::Debit ? Side::Credit : Side::Debit, $partial, $paidRate, $party),
    ]));
    $items = $this->ledger->reports->openItems($this->ledger->reports->snapshot($this->book, '2026-02-28'));
    $paidItem = collect($items)->first(fn ($item) => $item->journalId === $payment->id);
    $allocation = $this->ledger->settlements->allocate(new AllocationRequest($this->book, 'partial', $side === Side::Debit ? $original->id : $paidItem->id,
        $side === Side::Debit ? $paidItem->id : $original->id, $partial, '2026-02-02', 'Partial settlement', $realizedGain, $realizedLoss));
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-02-28');
    expect($this->ledger->reports->openItems($snapshot)[0]->functionalOutstanding->decimal())->toBe('7800.00');
    $balances = collect($this->ledger->reports->trialBalance($snapshot))->keyBy('accountId');
    expect($balances[$unrealizedGain]->closing->decimal())->toBe('0.00');
    expect($balances[$unrealizedLoss]->closing->decimal())->toBe('0.00');
    expect($balances[$side === Side::Debit ? $realizedGain : $realizedLoss]->closing->decimal())->toBe($side === Side::Debit ? '-600.00' : '600.00');
    $newRate = new ExchangeRate($usd, $this->kes, '150', '2026-02-03', 'next-close', Rounding::HalfEven);
    $this->ledger->revaluations->revalue($this->book, $original->id, 'remaining', '2026-02-03', $newRate, $unrealizedGain, $unrealizedLoss, 'Revalue remaining balance');
    expect($this->ledger->reports->openItems($this->ledger->reports->snapshot($this->book, '2026-02-28'))[0]->functionalOutstanding->decimal())->toBe('9000.00');
    $this->ledger->settlements->release($this->book, $allocation, 'release', '2026-02-04', 'Release partial settlement');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-02-28');
    $items = collect($this->ledger->reports->openItems($snapshot))->keyBy('id');
    expect($items[$original->id]->functionalOutstanding->decimal())->toBe('13000.00');
    expect($items[$paidItem->id]->functionalOutstanding->decimal())->toBe('5800.00');
    expect($this->ledger->reports->controlReconciliation($snapshot, $control)->decimal())->toBe('0.00');
    $this->storage->run(Permission::Read, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE'), $this->book);
})->with([Side::Debit, Side::Credit]);

it('replays a clearing without clearing valuations that were created later', function () {
    $usd = Currency::iso('USD');
    $ar = $this->ledger->catalog->account($this->book, '1100', 'USD receivables', AccountType::Asset, $usd, control: true);
    $party = $this->ledger->catalog->party($this->entity, 'customer', 'Customer');
    $gain = $this->ledger->catalog->account($this->book, '4100', 'Unrealized gain', AccountType::Income);
    $loss = $this->ledger->catalog->account($this->book, '5100', 'Unrealized loss', AccountType::Expense);
    $rate = new ExchangeRate($usd, $this->kes, '130', '2026-01-01', 'original', Rounding::HalfEven);
    $amount = Money::of('100', $usd);
    $this->ledger->journals->post(new JournalRequest($this->book, 'invoice', '2026-01-01', 'Invoice', [
        new Posting($ar, Side::Debit, $amount, $rate, $party), new Posting($this->income, Side::Credit, $rate->convert($amount)),
    ]));
    $item = $this->ledger->reports->openItems($this->ledger->reports->snapshot($this->book, '2026-01-31'))[0];
    $this->ledger->revaluations->revalue($this->book, $item->id, 'january', '2026-01-31', new ExchangeRate($usd, $this->kes, '140', '2026-01-31', 'closing', Rounding::HalfEven), $gain, $loss, 'January closing');
    $cleared = $this->ledger->revaluations->clear($this->book, $item->id, 'clear-january', '2026-02-01', 'Reverse January closing');
    $this->ledger->revaluations->revalue($this->book, $item->id, 'february', '2026-02-02', new ExchangeRate($usd, $this->kes, '150', '2026-02-02', 'next-closing', Rounding::HalfEven), $gain, $loss, 'New valuation');
    $replayed = $this->ledger->revaluations->clear($this->book, $item->id, 'clear-january', '2026-02-01', 'Reverse January closing');
    expect(array_column($replayed, 'id'))->toBe(array_column($cleared, 'id'));
    expect($this->ledger->reports->openItems($this->ledger->reports->snapshot($this->book, '2026-02-28'))[0]->functionalOutstanding->decimal())->toBe('15000.00');

    try {
        $this->ledger->revaluations->clear($this->book, $item->id, 'clear-january', '2026-02-03', 'Changed correction');
        test()->fail('Clearing retries must retain their original inputs.');
    } catch (LedgerException $exception) {
        expect($exception->getMessage())->toContain('different content');
    }

    $this->storage->run(Permission::Read, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE'), $this->book);
});
