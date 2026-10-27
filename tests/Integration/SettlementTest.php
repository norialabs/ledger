<?php

declare(strict_types=1);

use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\AllocationRequest;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Money\Rounding;

beforeEach(function () {
    $this->party = $this->ledger->catalog->party($this->entity, 'PARENT-1', 'Parent');
    $this->ar = $this->ledger->catalog->account($this->book, '1100', 'Receivables', AccountType::Asset, control: true);
});

it('allocates a partial payment and preserves historical outstanding', function () {
    $invoice = $this->ledger->journals->post(new JournalRequest($this->book, 'invoice', '2026-01-01', 'Term fees', [
        new Posting($this->ar, Side::Debit, Money::of('100', $this->kes), partyId: $this->party, dueOn: '2026-01-31'),
        new Posting($this->income, Side::Credit, Money::of('100', $this->kes)),
    ]));
    $this->ledger->journals->post(new JournalRequest($this->book, 'payment', '2026-02-01', 'Receipt', [
        new Posting($this->cash, Side::Debit, Money::of('40', $this->kes)),
        new Posting($this->ar, Side::Credit, Money::of('40', $this->kes), partyId: $this->party),
    ]));
    $before = $this->ledger->reports->snapshot($this->book, '2026-02-28');
    $items = $this->ledger->reports->openItems($before);
    $request = new AllocationRequest($this->book, 'allocate', $items[0]->id, $items[1]->id, Money::of('40', $this->kes), '2026-02-02', 'Apply receipt');
    $id = $this->ledger->settlements->allocate($request);
    expect($this->ledger->settlements->allocate($request))->toBe($id);
    $after = $this->ledger->reports->snapshot($this->book, '2026-02-28');
    $remaining = $this->ledger->reports->openItems($after);
    expect($remaining)->toHaveCount(1);
    expect($remaining[0]->journalId)->toBe($invoice->id);
    expect($remaining[0]->outstanding->decimal())->toBe('60.00');
    expect($this->ledger->reports->openItems($before))->toHaveCount(2);
    $this->ledger->settlements->release($this->book, $id, 'release', '2026-02-03', 'Incorrect allocation');
    expect($this->ledger->reports->openItems($this->ledger->reports->snapshot($this->book, '2026-02-28')))->toHaveCount(2);
    expect($this->ledger->reports->openItems($after))->toHaveCount(1);
});

it('clears the original and reversal open items without deleting either', function () {
    $invoice = $this->ledger->journals->post(new JournalRequest($this->book, 'invoice', '2026-01-01', 'Term fees', [
        new Posting($this->ar, Side::Debit, Money::of('100', $this->kes), partyId: $this->party),
        new Posting($this->income, Side::Credit, Money::of('100', $this->kes)),
    ]));
    $this->ledger->journals->reverse($this->book, $invoice->id, 'reverse', '2026-02-01', 'Cancel fees');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-02-28');
    expect($this->ledger->reports->openItems($snapshot))->toBe([]);
    expect($this->ledger->reports->openItems($snapshot, includeSettled: true))->toHaveCount(2);
});

it('posts and reverses realized exchange differences while retaining the original foreign amounts', function () {
    $usd = new Currency('USD', 2);
    $ar = $this->ledger->catalog->account($this->book, '1101', 'USD receivables', AccountType::Asset, $usd, control: true);
    $gain = $this->ledger->catalog->account($this->book, '4100', 'Exchange gains', AccountType::Income);
    $loss = $this->ledger->catalog->account($this->book, '5100', 'Exchange losses', AccountType::Expense);
    $amount = Money::of('100', $usd);
    $invoiceRate = new ExchangeRate($usd, $this->kes, '130', '2026-01-01', 'treasury:v1', Rounding::HalfEven);
    $paymentRate = new ExchangeRate($usd, $this->kes, '135', '2026-02-01', 'treasury:v2', Rounding::HalfEven);
    $this->ledger->journals->post(new JournalRequest($this->book, 'usd-invoice', '2026-01-01', 'Invoice', [
        new Posting($ar, Side::Debit, $amount, $invoiceRate, $this->party),
        new Posting($this->income, Side::Credit, $invoiceRate->convert($amount)),
    ]));
    $this->ledger->journals->post(new JournalRequest($this->book, 'usd-payment', '2026-02-01', 'Receipt', [
        new Posting($this->cash, Side::Debit, $paymentRate->convert($amount)),
        new Posting($ar, Side::Credit, $amount, $paymentRate, $this->party),
    ]));
    $items = $this->ledger->reports->openItems($this->ledger->reports->snapshot($this->book, '2026-02-28'));
    $allocation = $this->ledger->settlements->allocate(new AllocationRequest($this->book, 'fx-allocation', $items[0]->id, $items[1]->id, $amount, '2026-02-02', 'Settle USD', $gain, $loss));
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-02-28');
    expect($this->ledger->reports->openItems($snapshot))->toBe([]);
    $balances = collect($this->ledger->reports->trialBalance($snapshot))->keyBy('accountId');
    expect($balances[$ar]->closing->decimal())->toBe('0.00');
    expect($balances[$gain]->closing->decimal())->toBe('-500.00');
    $this->ledger->settlements->release($this->book, $allocation, 'fx-release', '2026-02-03', 'Release settlement');
    $balances = collect($this->ledger->reports->trialBalance($this->ledger->reports->snapshot($this->book, '2026-02-28')))->keyBy('accountId');
    expect($balances[$gain]->closing->decimal())->toBe('0.00');
});
