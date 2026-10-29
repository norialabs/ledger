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
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Money\Rounding;
use Noria\Ledger\Reporting\OpenItem;
use Noria\Ledger\Reporting\PartyBalance;
use Noria\Ledger\Reporting\Snapshot;
use Noria\Ledger\Session;
use Noria\Ledger\Support\Row;

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
    $cashBasis = $this->ledger->reports->cashBasisIncomeStatement($snapshot, '2026-01-01');
    expect($cashBasis['accounts'][$gain]->decimal())->toBe('-500.00')
        ->and($cashBasis['income']->decimal())->toBe('13500.00');
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

    $reinstatement = $this->ledger->settlements->reinstate($this->book, $writeOff, 'reinstate', '2026-04-15', 'Customer acknowledged debt');
    expect($this->ledger->settlements->reinstate($this->book, $writeOff, 'reinstate', '2026-04-15', 'Customer acknowledged debt'))->toBe($reinstatement)
        ->and(fn () => $this->ledger->settlements->reinstate($this->book, $writeOff, 'reinstate', '2026-04-15', 'Different reason'))->toThrow(LedgerException::class, 'different write-off');
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
    $payable = $this->ledger->catalog->account($this->book, '2100', 'Payables', AccountType::Liability, control: true);
    $this->ledger->journals->post(new JournalRequest($this->book, 'supplier-advance', '2026-06-10', 'Supplier refund due', [
        new Posting($this->cash, Side::Debit, Money::of('10', $this->kes)), new Posting($payable, Side::Credit, Money::of('10', $this->kes), partyId: $this->party),
    ]));

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
    $credit = $items->first(fn (OpenItem $item): bool => $item->side === Side::Credit && $item->accountId === $this->ar) ?? throw new RuntimeException('The receipt has no open credit.');
    $this->ledger->settlements->allocate(new AllocationRequest($this->book, 'apply-old', $oldItem->id,
        $credit->id, Money::of('200', $this->kes), '2026-07-05', 'Apply receipt'));
    $this->ledger->settlements->provide($this->book, 'ecl-july', $this->ar, $allowance, $expense, '2026-07-05', $matrix, 'July provision matrix');
    $july = byAccount($this->ledger->reports->trialBalance($this->ledger->reports->snapshot($this->book, '2026-07-05')));
    expect($july[$allowance]->closing->decimal())->toBe('-100.00')->and($july[$expense]->closing->decimal())->toBe('100.00');
});

it('replaces the matrix with specific losses, overlays only collective exposure and retains exact assessment history', function () {
    $allowance = $this->ledger->catalog->account($this->book, '1190', 'Allowance', AccountType::Asset, normalSide: Side::Credit);
    $expense = $this->ledger->catalog->account($this->book, '5810', 'Credit losses', AccountType::Expense);
    settlementInvoice($this->ledger, $this->book, $this->ar, $this->income, $this->party, 'specific', '2026-06-01', '200', '2026-06-30', $this->kes);
    $item = $this->ledger->reports->openItems($this->ledger->reports->snapshot($this->book, '2026-06-30'))[0];
    settlementInvoice($this->ledger, $this->book, $this->ar, $this->income, $this->party, 'collective', '2026-06-01', '800', '2026-06-30', $this->kes);
    $provide = fn (string $key, string $loss): string => $this->ledger->settlements->provide($this->book, $key, $this->ar, $allowance, $expense,
        '2026-06-30', ['current' => '0.05'], 'Reviewed assessment', [['item_id' => $item->id, 'loss_minor' => $loss, 'reference' => 'Reviewed recovery estimate:1']], ['current' => '1000'], 'credit-policy:3');
    $run = $provide('first', '10000');
    expect($provide('first', '10000'))->toBe($run);
    $saved = $this->ledger->reports->snapshot($this->book, '2026-06-30');
    expect(byAccount($this->ledger->reports->trialBalance($saved))[$allowance]->closing->decimal())->toBe('-150.00');
    $provide('reassessed', '6000');
    $provide('unchanged', '6000');
    $after = $this->ledger->reports->snapshot($this->book, '2026-06-30');
    expect(byAccount($this->ledger->reports->trialBalance($after))[$allowance]->closing->decimal())->toBe('-110.00')
        ->and(byAccount($this->ledger->reports->trialBalance($saved))[$allowance]->closing->decimal())->toBe('-150.00')
        ->and(fn () => $provide('first', '6000'))->toThrow(LedgerException::class, 'different allowance');
    $this->storage->run(Permission::Read, function () use ($run, $item): void {
        $evidence = $this->storage->table('ledger_allowance_runs')->where('id', $run)->first() ?? throw new RuntimeException('Missing allowance run.');
        expect(Row::json($evidence, 'inputs'))->toMatchArray(['policy_reference' => 'credit-policy:3',
            'assessments' => [['item_id' => $item->id, 'loss_minor' => '10000', 'reference' => 'Reviewed recovery estimate:1']], 'overlays' => ['current' => '1000']])
            ->and(Row::json($evidence, 'measurement'))->toMatchArray(['specific_minor' => '10000', 'collective_minor' => '5000', 'exposure' => ['current' => '80000']]);
    }, $this->book);
    $writeOff = $this->ledger->settlements->writeOff($this->book, 'write-off', $item->id, Money::of('30', $this->kes), $allowance, '2026-07-01', 'Partial write-off');
    $july = $this->ledger->reports->snapshot($this->book, '2026-07-31');
    $this->ledger->settlements->reinstate($this->book, $writeOff, 'recover', '2026-07-02', 'Reviewed recovery reinstatement');
    $movement = fn (Snapshot $snapshot, string $from): array => array_map(fn (Money $amount): string => $amount->decimal(), $this->ledger->settlements->allowanceMovement($snapshot, $allowance, $from));
    expect($movement($saved, '2026-01-01'))->toBe(['opening' => '0.00', 'charge' => '150.00', 'writeOffs' => '0.00', 'recoveries' => '0.00', 'other' => '0.00', 'closing' => '150.00'])
        ->and($movement($after, '2026-01-01')['charge'])->toBe('110.00')
        ->and($movement($july, '2026-07-01'))->toBe(['opening' => '110.00', 'charge' => '0.00', 'writeOffs' => '30.00', 'recoveries' => '0.00', 'other' => '0.00', 'closing' => '80.00'])
        ->and($movement($this->ledger->reports->snapshot($this->book, '2026-07-31'), '2026-07-01'))->toBe(['opening' => '110.00', 'charge' => '0.00', 'writeOffs' => '30.00', 'recoveries' => '30.00', 'other' => '0.00', 'closing' => '110.00']);
    $this->ledger->journals->post(new JournalRequest($this->book, 'other', '2026-07-03', 'Reviewed ordinary adjustment', [
        new Posting($expense, Side::Debit, Money::of('5', $this->kes)), new Posting($allowance, Side::Credit, Money::of('5', $this->kes)),
    ]));
    expect($movement($this->ledger->reports->snapshot($this->book, '2026-07-31'), '2026-07-01'))->toMatchArray(['other' => '5.00', 'closing' => '115.00']);
});

it('assesses a party across buckets without absorbing another party or unapplied credits', function () {
    $allowance = $this->ledger->catalog->account($this->book, '1190', 'Allowance', AccountType::Asset, normalSide: Side::Credit);
    $expense = $this->ledger->catalog->account($this->book, '5810', 'Credit losses', AccountType::Expense);
    $other = $this->ledger->catalog->party($this->entity, 'OTHER', 'Other customer');
    settlementInvoice($this->ledger, $this->book, $this->ar, $this->income, $this->party, 'old', '2026-01-01', '100', '2026-01-31', $this->kes);
    settlementInvoice($this->ledger, $this->book, $this->ar, $this->income, $this->party, 'new', '2026-06-01', '100', '2026-06-30', $this->kes);
    settlementInvoice($this->ledger, $this->book, $this->ar, $this->income, $other, 'other', '2026-06-01', '800', '2026-06-30', $this->kes);
    $this->ledger->journals->post(new JournalRequest($this->book, 'credit', '2026-06-01', 'Unapplied receipt', [
        new Posting($this->cash, Side::Debit, Money::of('50', $this->kes)), new Posting($this->ar, Side::Credit, Money::of('50', $this->kes), partyId: $this->party),
    ]));
    $this->ledger->settlements->provide($this->book, 'party', $this->ar, $allowance, $expense, '2026-06-30', ['current' => '0.05', '91+' => '1'], 'Party assessment',
        [['party_id' => $this->party, 'loss_minor' => '10000', 'reference' => 'Reviewed party estimate']], ['current' => '-1000'], 'policy:1');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-06-30');
    expect(byAccount($this->ledger->reports->trialBalance($snapshot))[$allowance]->closing->decimal())->toBe('-130.00');
});

it('rejects ambiguous specific assessments and unsupported overlays atomically', function (string $case, string $message) {
    $allowance = $this->ledger->catalog->account($this->book, '1190', 'Allowance', AccountType::Asset, normalSide: Side::Credit);
    $expense = $this->ledger->catalog->account($this->book, '5810', 'Credit losses', AccountType::Expense);
    settlementInvoice($this->ledger, $this->book, $this->ar, $this->income, $this->party, 'item', '2026-06-01', '200', '2026-06-30', $this->kes);
    $item = $this->ledger->reports->openItems($this->ledger->reports->snapshot($this->book, '2026-06-30'))[0];
    $assessment = ['item_id' => $item->id, 'loss_minor' => '10000', 'reference' => 'Reviewed'];
    $assessments = match ($case) {
        'overlap' => [$assessment, ['party_id' => $this->party, 'loss_minor' => '0', 'reference' => 'Reviewed']],
        'duplicate' => [$assessment, $assessment],
        'excess' => [[...$assessment, 'loss_minor' => '20001']],
        'missing' => [[...$assessment, 'item_id' => (string) Str::uuid7()]],
        'policy' => [$assessment], default => [],
    };
    $overlays = match ($case) {
        'negative' => ['current' => '-1001'], 'excess-overlay' => ['current' => '19001'], 'bucket' => ['91+' => '1'], default => []
    };
    $before = $this->ledger->reports->snapshot($this->book, '2026-06-30');
    expect(fn () => $this->ledger->settlements->provide($this->book, 'invalid', $this->ar, $allowance, $expense, '2026-06-30', ['current' => '0.05'], 'Review',
        $assessments, $overlays, $case === 'policy' ? null : 'policy:1'))->toThrow(QueryException::class, $message)
        ->and($this->ledger->reports->snapshot($this->book, '2026-06-30')->throughSequence)->toBe($before->throughSequence);
})->with([
    ['overlap', 'overlap'], ['duplicate', 'overlap'], ['excess', 'exceeds exposure'], ['missing', 'no eligible receivables'],
    ['policy', 'versioned policy'], ['negative', 'within exposure'], ['excess-overlay', 'within exposure'], ['bucket', 'matrix buckets'],
]);

it('independently rejects invented exposure even when a forged no-journal allowance is internally consistent', function () {
    $allowance = $this->ledger->catalog->account($this->book, '1190', 'Allowance', AccountType::Asset, normalSide: Side::Credit);
    $expense = $this->ledger->catalog->account($this->book, '5810', 'Credit losses', AccountType::Expense);
    $cutoff = $this->ledger->reports->snapshot($this->book, '2026-06-30')->throughSequence;
    expect(fn () => $this->storage->run(Permission::Allocate, fn () => $this->storage->insert('ledger_allowance_runs', [
        'id' => (string) Str::uuid7(), 'book_id' => $this->book, 'idempotency_key' => 'invented', 'fingerprint' => str_repeat('a', 64),
        'control_account_id' => $this->ar, 'allowance_account_id' => $allowance, 'expense_account_id' => $expense, 'effective_on' => '2026-06-30',
        'through_sequence' => $cutoff, 'matrix' => '{"current":"0"}', 'exposure' => '{"current":"10000"}', 'required_minor' => '0', 'previous_minor' => '0',
        'measurement' => '{"exposure":{"current":"10000"},"assessments":[],"items":[],"specific_minor":"0","collective_minor":"0","required_minor":"0"}', 'reason' => 'Forged',
    ]), $this->book))->toThrow(QueryException::class, 'independently measured');
});

it('measures specific foreign losses against revalued functional exposure after partial settlement', function () {
    $usd = Currency::iso('USD');
    $ar = $this->ledger->catalog->account($this->book, '1110', 'USD receivables', AccountType::Asset, $usd, control: true);
    $allowance = $this->ledger->catalog->account($this->book, '1190', 'Allowance', AccountType::Asset, normalSide: Side::Credit);
    $expense = $this->ledger->catalog->account($this->book, '5810', 'Credit losses', AccountType::Expense);
    $gain = $this->ledger->catalog->account($this->book, '4100', 'FX gain', AccountType::Income);
    $loss = $this->ledger->catalog->account($this->book, '5100', 'FX loss', AccountType::Expense);
    $rate = new ExchangeRate($usd, $this->kes, '130', '2026-01-01', 'initial', Rounding::HalfEven);
    $this->ledger->journals->post(new JournalRequest($this->book, 'foreign', '2026-01-01', 'Foreign receivable', [
        new Posting($ar, Side::Debit, Money::of('100', $usd), $rate, $this->party), new Posting($this->income, Side::Credit, Money::of('13000', $this->kes)),
    ]));
    $this->ledger->journals->post(new JournalRequest($this->book, 'receipt', '2026-01-02', 'Receipt', [
        new Posting($this->cash, Side::Debit, Money::of('5200', $this->kes)), new Posting($ar, Side::Credit, Money::of('40', $usd), $rate, $this->party),
    ]));
    $items = $this->ledger->reports->openItems($this->ledger->reports->snapshot($this->book, '2026-01-31'));
    $this->ledger->settlements->allocate(new AllocationRequest($this->book, 'apply', $items[0]->id, $items[1]->id, Money::of('40', $usd), '2026-01-02', 'Apply'));
    $this->ledger->revaluations->revalue($this->book, $items[0]->id, 'revalue', '2026-01-31', new ExchangeRate($usd, $this->kes, '140', '2026-01-31', 'closing', Rounding::HalfEven), $gain, $loss, 'Closing value');
    $run = $this->ledger->settlements->provide($this->book, 'foreign-loss', $ar, $allowance, $expense, '2026-01-31', ['current' => '0.05', '1-30' => '0.05'], 'Reviewed USD recovery',
        [['item_id' => $items[0]->id, 'loss_minor' => '820000', 'reference' => 'Functional loss exceeds historical 7800 but is below revalued 8400']], [], 'policy:1');
    expect(byAccount($this->ledger->reports->trialBalance($this->ledger->reports->snapshot($this->book, '2026-01-31')))[$allowance]->closing->decimal())->toBe('-8200.00');
    $this->storage->run(Permission::Read, function () use ($run): void {
        $row = $this->storage->table('ledger_allowance_runs')->where('id', $run)->first() ?? throw new RuntimeException('Missing assessment.');
        expect(Row::json($row, 'measurement')['assessments'])->toBe([['assessment' => 1, 'loss_minor' => '820000', 'exposure_minor' => '840000']]);
    }, $this->book);
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
    $bills = ['bill-1' => $bill('bill-1', '2026-01-01', '2026-01-31', '300'), 'bill-2' => $bill('bill-2', '2026-02-01', '2026-02-28', '400'), 'bill-3' => $bill('bill-3', '2026-03-01', '2026-03-31', '500'),
        'bill-4' => $bill('bill-4', '2026-03-02', '2026-04-30', '100')];
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
        ->and(($open[$bills['bill-4']] ?? null)?->outstanding->decimal())->toBe('100.00')
        ->and($open->has($receipt))->toBeFalse();
});

it('scopes party open items to that party without losing their allocations or revaluations', function () {
    $usd = Currency::iso('USD');
    $usdAr = $this->ledger->catalog->account($this->book, '1110', 'USD receivables', AccountType::Asset, $usd, control: true);
    $gain = $this->ledger->catalog->account($this->book, '4100', 'Unrealized FX', AccountType::Income);
    $loss = $this->ledger->catalog->account($this->book, '5100', 'FX losses', AccountType::Expense);
    $other = $this->ledger->catalog->party($this->entity, 'PARENT-2', 'Other parent');
    $initial = new ExchangeRate($usd, $this->kes, '130', '2026-01-01', 'treasury:initial', Rounding::HalfEven);
    $closing = new ExchangeRate($usd, $this->kes, '140', '2026-01-31', 'treasury:closing', Rounding::HalfEven);
    foreach (['own' => [$this->party, '100', '40'], 'other' => [$other, '50', '20']] as $key => [$party, $billed, $paid]) {
        settlementInvoice($this->ledger, $this->book, $this->ar, $this->income, $party, 'invoice-'.$key, '2026-01-01', $billed, '2026-01-31', $this->kes);
        $this->ledger->journals->post(new JournalRequest($this->book, 'receipt-'.$key, '2026-01-02', 'Receipt', [
            new Posting($this->cash, Side::Debit, Money::of($paid, $this->kes)), new Posting($this->ar, Side::Credit, Money::of($paid, $this->kes), partyId: $party),
        ]));
        $this->ledger->journals->post(new JournalRequest($this->book, 'usd-'.$key, '2026-01-01', 'USD invoice', [
            new Posting($usdAr, Side::Debit, Money::of($billed, $usd), $initial, $party), new Posting($this->income, Side::Credit, $initial->convert(Money::of($billed, $usd))),
        ]));
    }
    $items = collect($this->ledger->reports->openItems($this->ledger->reports->snapshot($this->book, '2026-01-31')));
    foreach (['own' => $this->party, 'other' => $other] as $key => $party) {
        $receipt = $items->first(fn ($item) => $item->partyId === $party && $item->side === Side::Credit) ?? throw new RuntimeException('Missing receipt.');
        $this->ledger->settlements->allocateOldest($this->book, $receipt->id, '2026-01-02', 'Apply receipt');
        $foreign = $items->first(fn ($item) => $item->partyId === $party && $item->accountId === $usdAr) ?? throw new RuntimeException('Missing USD invoice.');
        $this->ledger->revaluations->revalue($this->book, $foreign->id, 'revalue-'.$key, '2026-01-31', $closing, $gain, $loss, 'Month-end revaluation');
    }

    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $summary = fn (OpenItem ...$items): array => array_map(fn (OpenItem $item): array => [$item->id, $item->outstanding->decimal(), $item->functionalOutstanding->decimal()], $items);
    $scoped = $this->ledger->reports->openItems($snapshot, $this->party);
    $whole = array_values(array_filter($this->ledger->reports->openItems($snapshot), fn ($item) => $item->partyId === $this->party));
    expect($summary(...$scoped))->toBe($summary(...$whole))
        ->and(array_map(fn (OpenItem $item): array => [$item->outstanding->decimal(), $item->functionalOutstanding->decimal()], $scoped))->toBe([['60.00', '60.00'], ['100.00', '14000.00']])
        ->and(count($this->ledger->reports->openItems($snapshot, $this->party, includeSettled: true)))->toBe(3);
});

it('pages open items, ages them in SQL and lists party balances with the same totals as reading every item', function () {
    foreach (['north', 'south'] as $zone) {
        $this->ledger->catalog->dimensionValue($this->entity, 'zone', $zone);
    }
    $other = $this->ledger->catalog->party($this->entity, 'PARENT-2', 'Second parent');
    $settled = $this->ledger->catalog->party($this->entity, 'PARENT-3', 'Settled parent');
    $itemOf = fn (string $journal, string $party, string $date): string => collect($this->ledger->reports->openItems($this->ledger->reports->snapshot($this->book, $date), $party, includeSettled: true))
        ->firstWhere('journalId', $journal)->id ?? throw new RuntimeException('Missing open item.');
    $bill = function (string $key, string $party, string $date, string $amount, string $dueOn, string $zone) use ($itemOf): string {
        $journal = $this->ledger->journals->post(new JournalRequest($this->book, $key, $date, 'Bill '.$key, [
            new Posting($this->ar, Side::Debit, Money::of($amount, $this->kes), partyId: $party, dueOn: $dueOn, dimensions: ['zone' => $zone]),
            new Posting($this->income, Side::Credit, Money::of($amount, $this->kes)),
        ]))->id;

        return $itemOf($journal, $party, $date);
    };
    $receive = function (string $key, string $party, string $date, string $amount, string $zone) use ($itemOf): string {
        $journal = $this->ledger->journals->post(new JournalRequest($this->book, $key, $date, 'Receipt '.$key, [
            new Posting($this->cash, Side::Debit, Money::of($amount, $this->kes)),
            new Posting($this->ar, Side::Credit, Money::of($amount, $this->kes), partyId: $party, dimensions: ['zone' => $zone]),
        ]))->id;

        return $itemOf($journal, $party, $date);
    };
    $bill('a1', $this->party, '2026-01-01', '400', '2026-01-31', 'north');
    $bill('a2', $this->party, '2026-03-01', '300', '2026-03-31', 'north');
    $receive('a-receipt', $this->party, '2026-03-15', '100', 'north');
    $bill('b1', $other, '2026-01-01', '250', '2026-01-15', 'south');
    $b2 = $bill('b2', $other, '2026-02-01', '150', '2026-02-20', 'south');
    $this->ledger->settlements->allocate(new AllocationRequest($this->book, 'b-apply', $b2, $receive('b-receipt', $other, '2026-02-25', '50', 'south'), Money::of('50', $this->kes), '2026-02-25', 'Apply'));
    $c1 = $bill('c1', $settled, '2026-01-10', '80', '2026-01-20', 'north');
    $this->ledger->settlements->allocate(new AllocationRequest($this->book, 'c-apply', $c1, $receive('c-receipt', $settled, '2026-01-20', '80', 'north'), Money::of('80', $this->kes), '2026-01-20', 'Apply'));
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-04-30');

    $all = $this->ledger->reports->openItems($snapshot);
    $pages = [];
    $after = null;
    do {
        $page = $this->ledger->reports->openItems($snapshot, limit: 2, after: $after);
        $pages[] = count($page);
        $after = $page === [] ? null : end($page)->cursor();
        $paged = [...($paged ?? []), ...$page];
    } while (count($page) === 2);
    $inMemory = [];
    foreach ($all as $item) {
        $days = (int) (new DateTimeImmutable($item->dueOn))->diff(new DateTimeImmutable('2026-04-30'))->format('%r%a');
        $bucket = match (true) {
            $days <= 0 => 'current', $days <= 30 => '1-30', $days <= 60 => '31-60', $days <= 90 => '61-90', default => '91+',
        };
        $inMemory[$bucket] = ($inMemory[$bucket] ?? 0) + (int) $item->outstanding->minor->multipliedBy($item->side->sign())->toInt();
    }
    $decimals = fn (Money ...$buckets): array => array_map(fn (Money $money): string => $money->decimal(), $buckets);
    $aging = $this->ledger->reports->aging($snapshot);

    expect(array_map(fn (OpenItem $item): string => $item->journalId, $all))->toHaveCount(5)
        ->and($pages)->toBe([2, 2, 1])
        ->and(array_map(fn (OpenItem $item): string => $item->id, $paged))->toBe(array_map(fn (OpenItem $item): string => $item->id, $all))
        ->and(array_map(fn (OpenItem $item): string => $item->id, iterator_to_array($this->ledger->reports->eachOpenItem($snapshot), false)))->toBe(array_map(fn (OpenItem $item): string => $item->id, $all))
        ->and(array_map(fn (OpenItem $item): string => $item->dueOn, $all))->toBe(['2026-01-31', '2026-01-15', '2026-02-20', '2026-03-31', '2026-03-15'])
        ->and($all[0]->dimensions)->toBe(['zone' => 'north'])
        ->and($decimals(...$aging['KES']))->toBe(['1-30' => '300.00', '31-60' => '-100.00', '61-90' => '500.00', '91+' => '250.00'])
        ->and(array_map(fn (Money $money): int => (int) $money->minor->toInt(), $aging['KES']))->toEqualCanonicalizing($inMemory)
        ->and($decimals(...$this->ledger->reports->aging($snapshot, accountId: $this->ar, dimensions: ['zone' => 'north'])['KES']))->toBe(['1-30' => '300.00', '31-60' => '-100.00', '61-90' => '400.00'])
        ->and($decimals(...$this->ledger->reports->aging($snapshot, $other)['KES']))->toBe(['61-90' => '100.00', '91+' => '250.00'])
        ->and($decimals(...$this->ledger->reports->exposure($snapshot, $this->ar)))->toBe(['current' => '0.00', '1-30' => '300.00', '31-60' => '0.00', '61-90' => '500.00', '91+' => '250.00'])
        ->and($this->ledger->reports->openItem($snapshot, $c1)?->outstanding->decimal())->toBe('0.00')
        ->and($this->ledger->reports->openItem($this->ledger->reports->snapshot($this->book, '2025-12-31'), $c1))->toBeNull()
        ->and(fn () => $this->ledger->reports->openItems($snapshot, after: '2026-01-01.x.1'))->toThrow(LedgerException::class, 'Invalid cursor');

    $expected = collect([$this->party => '600.00', $other => '350.00', $settled => '0.00'])->sortKeys();
    $balances = fn (PartyBalance ...$page): array => collect($page)->mapWithKeys(fn (PartyBalance $balance): array => [$balance->partyId => $balance->balance->decimal()])->all();
    $first = $this->ledger->reports->partyBalances($snapshot, $this->ar, limit: 1, includeZero: true);
    expect($balances(...$this->ledger->reports->partyBalances($snapshot, $this->ar, includeZero: true)))->toBe($expected->all())
        ->and($balances(...$this->ledger->reports->partyBalances($snapshot, $this->ar)))->toBe($expected->reject(fn (string $balance): bool => $balance === '0.00')->all())
        ->and($balances(...$first, ...$this->ledger->reports->partyBalances($snapshot, $this->ar, limit: 1, afterPartyId: $first[0]->partyId, includeZero: true)))->toBe($expected->take(2)->all())
        ->and($this->ledger->reports->controlReconciliation($snapshot, $this->ar)->decimal())->toBe('0.00');
});
