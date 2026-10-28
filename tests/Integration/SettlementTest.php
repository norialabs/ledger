<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\AllocationRequest;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Accounting\Source;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Money\Rounding;
use Noria\Ledger\Session;

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
    $balances = byAccount($this->ledger->reports->trialBalance($snapshot));
    expect($balances[$ar]->closing->decimal())->toBe('0.00');
    expect($balances[$gain]->closing->decimal())->toBe('-500.00');
    $this->ledger->settlements->release($this->book, $allocation, 'fx-release', '2026-02-03', 'Release settlement');
    $balances = byAccount($this->ledger->reports->trialBalance($this->ledger->reports->snapshot($this->book, '2026-02-28')));
    expect($balances[$gain]->closing->decimal())->toBe('0.00');
});

function settlementInvoice(Session $ledger, string $book, string $ar, string $income, string $party, string $key, string $date, string $amount, string $dueOn, Currency $currency): string
{
    return $ledger->journals->post(new JournalRequest($book, $key, $date, 'Invoice '.$key, [
        new Posting($ar, Side::Debit, Money::of($amount, $currency), partyId: $party, dueOn: $dueOn),
        new Posting($income, Side::Credit, Money::of($amount, $currency)),
    ]))->id;
}

it('writes off part of a receivable through an exact settling journal and reinstates it exactly', function () {
    $badDebts = $this->ledger->catalog->account($this->book, '5800', 'Bad debts', AccountType::Expense);
    $invoice = settlementInvoice($this->ledger, $this->book, $this->ar, $this->income, $this->party, 'invoice', '2026-01-01', '100', '2026-01-31', $this->kes);
    $item = collect($this->ledger->reports->openItems($this->ledger->reports->snapshot($this->book, '2026-03-31')))->firstWhere('journalId', $invoice) ?? throw new RuntimeException('The invoice has no open item.');
    $writeOff = $this->ledger->settlements->writeOff($this->book, 'write-off', $item->id, Money::of('30', $this->kes), $badDebts, '2026-03-31', 'Uncollectable balance');
    expect($this->ledger->settlements->writeOff($this->book, 'write-off', $item->id, Money::of('30', $this->kes), $badDebts, '2026-03-31', 'Uncollectable balance'))->toBe($writeOff);

    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-03-31');
    $open = $this->ledger->reports->openItems($snapshot);
    $balances = byAccount($this->ledger->reports->trialBalance($snapshot));
    expect($open)->toHaveCount(1)
        ->and($open[0]->outstanding->decimal())->toBe('70.00')
        ->and($balances[$this->ar]->closing->decimal())->toBe('70.00')
        ->and($balances[$badDebts]->closing->decimal())->toBe('30.00')
        ->and($this->ledger->reports->controlReconciliation($snapshot, $this->ar)->decimal())->toBe('0.00');

    $this->ledger->settlements->reinstate($this->book, $writeOff, 'reinstate', '2026-04-15', 'Customer acknowledged debt');
    $after = $this->ledger->reports->snapshot($this->book, '2026-04-30');
    $reinstated = collect($this->ledger->reports->openItems($after))->firstWhere('journalId', $invoice) ?? throw new RuntimeException('The reinstated invoice has no open item.');
    expect($reinstated->outstanding->decimal())->toBe('100.00')
        ->and(byAccount($this->ledger->reports->trialBalance($after))[$badDebts]->closing->decimal())->toBe('0.00')
        ->and(fn () => $this->ledger->settlements->reinstate($this->book, $writeOff, 'reinstate-again', '2026-04-16', 'Twice'))->toThrow(QueryException::class);
});

it('writes back an unapplied credit balance to income', function () {
    $writeBack = $this->ledger->catalog->account($this->book, '4900', 'Unclaimed credits', AccountType::Income);
    $receipt = $this->ledger->journals->post(new JournalRequest($this->book, 'overpayment', '2026-01-10', 'Overpayment', [
        new Posting($this->cash, Side::Debit, Money::of('15', $this->kes)),
        new Posting($this->ar, Side::Credit, Money::of('15', $this->kes), partyId: $this->party),
    ]));
    $credit = $this->ledger->reports->openItems($this->ledger->reports->snapshot($this->book, '2026-06-30'))[0];
    $this->ledger->settlements->writeOff($this->book, 'write-back', $credit->id, Money::of('15', $this->kes), $writeBack, '2026-06-30', 'Unclaimed after six months');

    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-06-30');
    expect($this->ledger->reports->openItems($snapshot))->toBe([])
        ->and($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['income']->decimal())->toBe('15.00')
        ->and($receipt->id)->not->toBeEmpty();
});

it('writes off a foreign receivable at its carrying rate and posts realized FX on a revalued remainder', function () {
    $usd = new Currency('USD', 2);
    $usdAr = $this->ledger->catalog->account($this->book, '1110', 'USD receivables', AccountType::Asset, $usd, control: true);
    $badDebts = $this->ledger->catalog->account($this->book, '5800', 'Bad debts', AccountType::Expense);
    $invoice = $this->ledger->journals->post(new JournalRequest($this->book, 'usd-invoice', '2026-01-01', 'USD invoice', [
        new Posting($usdAr, Side::Debit, Money::of('100', $usd), new ExchangeRate($usd, $this->kes, '130', '2026-01-01', 'test', Rounding::HalfEven), $this->party, '2026-01-31'),
        new Posting($this->income, Side::Credit, Money::of('13000', $this->kes)),
    ]));
    $item = $this->ledger->reports->openItems($this->ledger->reports->snapshot($this->book, '2026-02-28'))[0];
    $this->ledger->settlements->writeOff($this->book, 'usd-write-off', $item->id, Money::of('40', $usd), $badDebts, '2026-02-28', 'Partial uncollectable');

    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-02-28');
    $balances = byAccount($this->ledger->reports->trialBalance($snapshot));
    $open = $this->ledger->reports->openItems($snapshot);
    expect($balances[$badDebts]->closing->decimal())->toBe('5200.00')
        ->and($balances[$usdAr]->closing->decimal())->toBe('7800.00')
        ->and($open[0]->journalId)->toBe($invoice->id)
        ->and($open[0]->outstanding->decimal())->toBe('60.00')
        ->and($open[0]->functionalOutstanding->decimal())->toBe('7800.00');
});

it('measures a receivable allowance from an aging provision matrix and adjusts it to each new requirement', function () {
    $allowance = $this->ledger->catalog->account($this->book, '1190', 'Allowance for credit losses', AccountType::Asset, normalSide: Side::Credit);
    $expense = $this->ledger->catalog->account($this->book, '5810', 'Expected credit losses', AccountType::Expense);
    settlementInvoice($this->ledger, $this->book, $this->ar, $this->income, $this->party, 'current', '2026-06-01', '1000', '2026-06-30', $this->kes);
    settlementInvoice($this->ledger, $this->book, $this->ar, $this->income, $this->party, 'late', '2026-04-01', '500', '2026-05-16', $this->kes);
    $old = settlementInvoice($this->ledger, $this->book, $this->ar, $this->income, $this->party, 'old', '2026-01-01', '200', '2026-03-01', $this->kes);
    $matrix = ['current' => '0.01', '1-30' => '0.05', '31-60' => '0.10', '61-90' => '0.25', '91+' => '0.5'];

    $run = $this->ledger->settlements->provide($this->book, 'ecl-june', $this->ar, $allowance, $expense, '2026-06-30', $matrix, 'June provision matrix');
    expect($this->ledger->settlements->provide($this->book, 'ecl-june', $this->ar, $allowance, $expense, '2026-06-30', $matrix, 'June provision matrix'))->toBe($run);
    $june = byAccount($this->ledger->reports->trialBalance($this->ledger->reports->snapshot($this->book, '2026-06-30')));
    expect($june[$allowance]->closing->decimal())->toBe('-160.00')->and($june[$expense]->closing->decimal())->toBe('160.00');

    $this->ledger->journals->post(new JournalRequest($this->book, 'old-paid', '2026-07-05', 'Receipt', [
        new Posting($this->cash, Side::Debit, Money::of('200', $this->kes)),
        new Posting($this->ar, Side::Credit, Money::of('200', $this->kes), partyId: $this->party),
    ]));
    $items = collect($this->ledger->reports->openItems($this->ledger->reports->snapshot($this->book, '2026-07-05')));
    $oldItem = $items->firstWhere('journalId', $old) ?? throw new RuntimeException('The old invoice has no open item.');
    $credit = $items->firstWhere('side', Side::Credit) ?? throw new RuntimeException('The receipt has no open credit.');
    $this->ledger->settlements->allocate(new AllocationRequest($this->book, 'apply-old', $oldItem->id,
        $credit->id, Money::of('200', $this->kes), '2026-07-05', 'Apply receipt'));
    $this->ledger->settlements->provide($this->book, 'ecl-july', $this->ar, $allowance, $expense, '2026-07-05', $matrix, 'July provision matrix');
    $july = byAccount($this->ledger->reports->trialBalance($this->ledger->reports->snapshot($this->book, '2026-07-05')));
    expect($july[$allowance]->closing->decimal())->toBe('-100.00')->and($july[$expense]->closing->decimal())->toBe('100.00');
});

it('independently rejects forged allowance and write-off evidence', function () {
    $allowance = $this->ledger->catalog->account($this->book, '1190', 'Allowance', AccountType::Asset, normalSide: Side::Credit);
    $expense = $this->ledger->catalog->account($this->book, '5810', 'Expected credit losses', AccountType::Expense);
    expect(fn () => $this->storage->run(Permission::Allocate, fn () => $this->storage->insert('ledger_allowance_runs', ['id' => (string) Str::uuid7(), 'book_id' => $this->book,
        'idempotency_key' => 'forged', 'fingerprint' => str_repeat('a', 64), 'control_account_id' => $this->ar, 'allowance_account_id' => $allowance,
        'expense_account_id' => $expense, 'effective_on' => '2026-06-30', 'matrix' => '{"current":"0.5"}', 'exposure' => '{"current":"1000"}',
        'required_minor' => '400', 'previous_minor' => '400', 'reason' => 'Forged']), $this->book))->toThrow(QueryException::class, 'does not match its matrix');

    $this->storage->run(Permission::Read, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE'), $this->book);
    $this->ledger->journals->post(new JournalRequest($this->book, 'fake-write-off', '2026-06-30', 'Fake', [
        new Posting($expense, Side::Debit, Money::of('10', $this->kes)),
        new Posting($this->ar, Side::Credit, Money::of('10', $this->kes), partyId: $this->party),
    ], source: new Source('write_off', (string) Str::uuid7())));
})->throws(QueryException::class, 'recorded subledger event');

it('allocates a receipt to the oldest open bills first and leaves any excess as a credit', function () {
    $bill = fn (string $key, string $date, string $due, string $amount): string => settlementInvoice($this->ledger, $this->book, $this->ar, $this->income, $this->party, $key, $date, $amount, $due, $this->kes);
    $bills = ['bill-1' => $bill('bill-1', '2026-01-01', '2026-01-31', '300'), 'bill-2' => $bill('bill-2', '2026-02-01', '2026-02-28', '400'), 'bill-3' => $bill('bill-3', '2026-03-01', '2026-03-31', '500')];
    $receipt = $this->ledger->journals->post(new JournalRequest($this->book, 'receipt', '2026-03-05', 'M-Pesa receipt', [
        new Posting($this->cash, Side::Debit, Money::of('1000', $this->kes)), new Posting($this->ar, Side::Credit, Money::of('1000', $this->kes), partyId: $this->party),
    ]))->id;
    $items = collect($this->ledger->reports->openItems($this->ledger->reports->snapshot($this->book, '2026-03-05')));
    $receiptItem = $items->firstWhere('journalId', $receipt) ?? throw new RuntimeException('The receipt has no open item.');
    $allocations = $this->ledger->settlements->allocateOldest($this->book, $receiptItem->id, '2026-03-05', 'Oldest bills first');

    $open = collect($this->ledger->reports->openItems($this->ledger->reports->snapshot($this->book, '2026-03-05')))->keyBy('journalId');
    $lastBill = $open[$bills['bill-3']] ?? throw new RuntimeException('bill-3 is no longer open.');
    expect($allocations)->toHaveCount(3)
        ->and($open->has($bills['bill-1']))->toBeFalse()
        ->and($open->has($bills['bill-2']))->toBeFalse()
        ->and($lastBill->outstanding->decimal())->toBe('200.00')
        ->and($open->has($receipt))->toBeFalse();
});
