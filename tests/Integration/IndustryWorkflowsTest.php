<?php

declare(strict_types=1);

use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\AllocationRequest;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Accounting\Source;
use Noria\Ledger\Configuration\Pack;
use Noria\Ledger\Documents\Charge;
use Noria\Ledger\Documents\DocumentKind;
use Noria\Ledger\Documents\DocumentRequest;
use Noria\Ledger\Documents\Obligation;
use Noria\Ledger\Documents\PaymentRequest;
use Noria\Ledger\Documents\TaxedDocumentRequest;
use Noria\Ledger\Documents\TaxLine;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Money\Rounding;
use Noria\Ledger\Session;
use Noria\Ledger\Tax\TaxRule;

/** @param list<TaxRule> $rules */
function taxDocumentConfiguration(Session $ledger, string $book, array $rules, bool $foreignControl = false): string
{
    $receivable = ['code' => '1100', 'name' => 'Receivables', 'type' => 'asset', 'control' => true];
    if ($foreignControl) {
        $receivable['currency'] = 'USD';
    }

    return $ledger->configurations->install($book, new Pack('example-tax-documents', 'v1', [
        $receivable,
        ['code' => '2000', 'name' => 'Payables', 'type' => 'liability', 'control' => true],
        ['code' => '2200', 'name' => 'Output tax', 'type' => 'liability'],
        ['code' => '1200', 'name' => 'Recoverable input tax', 'type' => 'asset'],
        ['code' => '5000', 'name' => 'Purchase costs', 'type' => 'expense'],
        ['code' => '5999', 'name' => 'Explicit rounding', 'type' => 'expense'],
    ], ['receivable' => '1100', 'payable' => '2000', 'revenue' => '4000', 'cost' => '5000', 'tax.output' => '2200', 'tax.input' => '1200', 'rounding' => '5999'], $rules));
}

it('keeps school sponsorship, installments, and excess payments distinct', function () {
    $parent = $this->ledger->catalog->party($this->entity, 'guardian-1', 'Guardian');
    $sponsor = $this->ledger->catalog->party($this->entity, 'sponsor-1', 'Sponsor');
    $ar = $this->ledger->catalog->account($this->book, '1100', 'Receivables', AccountType::Asset, control: true);
    $invoice = new DocumentRequest($this->book, 'fees-1', '2026-01-01', new Source('school.fees', 'FEES-1'), DocumentKind::SalesInvoice, $ar, [
        new Obligation($parent, Money::of('800', $this->kes), '2026-01-31'),
        new Obligation($sponsor, Money::of('400', $this->kes), '2026-03-31'),
    ], [new Charge($this->income, Money::of('1200', $this->kes), 'Tuition')], beneficiaryReference: 'student-1');
    $first = $this->ledger->documents->issue($invoice);
    expect($this->ledger->documents->issue($invoice)->id)->toBe($first->id);
    $this->ledger->documents->payment(new PaymentRequest($this->book, 'receipt-1', '2026-02-01', new Source('mpesa.receipt', 'RCPT-1'), $this->cash, $ar, $parent, Money::of('1000', $this->kes), payerReference: 'different-family-member'));
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-02-28');
    $parentItems = $this->ledger->reports->openItems($snapshot, $parent);
    $this->ledger->settlements->allocate(new AllocationRequest($this->book, 'apply-fees', $parentItems[0]->id, $parentItems[1]->id, Money::of('800', $this->kes), '2026-02-01', 'Pay guardian share'));
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-02-28');
    $items = $this->ledger->reports->openItems($snapshot, $parent);
    expect($items)->toHaveCount(1);
    expect($items[0]->side)->toBe(Side::Credit);
    expect($items[0]->outstanding->decimal())->toBe('200.00');
    expect($this->ledger->reports->aging($snapshot, $sponsor)['KES']['current']->decimal())->toBe('400.00');
    expect($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['income']->decimal())->toBe('1200.00');
});

it('separates the hospital beneficiary from patient and insurer obligations', function () {
    $patient = $this->ledger->catalog->party($this->entity, 'patient-guarantor', 'Patient guarantor');
    $insurer = $this->ledger->catalog->party($this->entity, 'insurer', 'Insurer');
    $ar = $this->ledger->catalog->account($this->book, '1100', 'Receivables', AccountType::Asset, control: true);
    $this->ledger->documents->issue(new DocumentRequest($this->book, 'hospital-bill', '2026-01-01', new Source('hospital.invoice', 'H-1'), DocumentKind::SalesInvoice, $ar, [
        new Obligation($patient, Money::of('150', $this->kes), '2026-01-01'),
        new Obligation($insurer, Money::of('450', $this->kes), '2026-02-28'),
    ], [new Charge($this->income, Money::of('600', $this->kes), 'Services at agreed contract price')], beneficiaryReference: 'encounter-reference-1'));
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    expect($this->ledger->reports->openItems($snapshot, $patient)[0]->outstanding->decimal())->toBe('150.00');
    expect($this->ledger->reports->openItems($snapshot, $insurer)[0]->outstanding->decimal())->toBe('450.00');
    expect($this->ledger->reports->balanceSheet($snapshot)['difference']->decimal())->toBe('0.00');
});

it('applies a utility deposit to arrears without recognizing the deposit as revenue', function () {
    $customer = $this->ledger->catalog->party($this->entity, 'meter-account', 'Water customer');
    $ar = $this->ledger->catalog->account($this->book, '1100', 'Receivables', AccountType::Asset, control: true);
    $deposits = $this->ledger->catalog->account($this->book, '2100', 'Refundable deposits', AccountType::Liability, control: true);
    $money = Money::of('300', $this->kes);
    $this->ledger->documents->payment(new PaymentRequest($this->book, 'deposit', '2026-01-01', new Source('utility.deposit', 'D-1'), $this->cash, $deposits, $customer, $money));
    $this->ledger->documents->issue(new DocumentRequest($this->book, 'consumption', '2026-02-01', new Source('utility.bill', 'B-1'), DocumentKind::SalesInvoice, $ar,
        [new Obligation($customer, $money, '2026-02-28')], [new Charge($this->income, $money, 'Metered consumption')]));
    $this->ledger->journals->post(new JournalRequest($this->book, 'apply-deposit', '2026-02-02', 'Apply refundable deposit', [
        new Posting($deposits, Side::Debit, $money, partyId: $customer), new Posting($ar, Side::Credit, $money, partyId: $customer),
    ]));
    $items = $this->ledger->reports->openItems($this->ledger->reports->snapshot($this->book, '2026-02-28'));

    foreach ([$ar, $deposits] as $account) {
        $debit = collect($items)->first(fn ($item) => $item->accountId === $account && $item->side === Side::Debit);
        $credit = collect($items)->first(fn ($item) => $item->accountId === $account && $item->side === Side::Credit);
        $this->ledger->settlements->allocate(new AllocationRequest($this->book, 'allocate-'.$account, $debit->id, $credit->id, $money, '2026-02-02', 'Apply deposit'));
    }

    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-02-28');
    expect($this->ledger->reports->openItems($snapshot))->toBe([]);
    expect($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['income']->decimal())->toBe('300.00');
});

it('posts supplier bills and disbursements with the opposite control direction', function () {
    $supplier = $this->ledger->catalog->party($this->entity, 'supplier', 'Supplier');
    $ap = $this->ledger->catalog->account($this->book, '2000', 'Payables', AccountType::Liability, control: true);
    $expense = $this->ledger->catalog->account($this->book, '5000', 'Supplies', AccountType::Expense);
    $money = Money::of('100', $this->kes);
    $this->ledger->documents->issue(new DocumentRequest($this->book, 'supplier-bill', '2026-01-01', new Source('supplier.bill', 'S-1'), DocumentKind::PurchaseBill, $ap,
        [new Obligation($supplier, $money, '2026-01-31')], [new Charge($expense, $money, 'Supplies')]));
    $this->ledger->documents->payment(new PaymentRequest($this->book, 'supplier-payment', '2026-01-15', new Source('bank.payment', 'P-1'), $this->cash, $ap, $supplier, $money, receipt: false));
    $items = $this->ledger->reports->openItems($this->ledger->reports->snapshot($this->book, '2026-01-31'));
    $this->ledger->settlements->allocate(new AllocationRequest($this->book, 'pay-supplier', $items[1]->id, $items[0]->id, $money, '2026-01-15', 'Settle bill'));
    expect($this->ledger->reports->openItems($this->ledger->reports->snapshot($this->book, '2026-01-31')))->toBe([]);
});

it('posts versioned tax documents with the correct sales and purchase credit directions', function (DocumentKind $kind, string $controlRole, string $chargeRole, string $taxRole, string $expectedTax) {
    $configuration = taxDocumentConfiguration($this->ledger, $this->book, [new TaxRule('EXAMPLE', '0.16', '2026-01-01', null, 'example:v1')]);
    $party = $this->ledger->catalog->party($this->entity, 'counterparty', 'Counterparty');
    $request = new TaxedDocumentRequest($this->book, 'tax-document', '2026-01-01', new Source('tax.example', '1'), $kind, $configuration, $controlRole,
        [new Obligation($party, Money::of('174', $this->kes), '2026-01-31')], [new TaxLine($chargeRole, '100', '1.5', 'Example taxable service', ['EXAMPLE'])], ['EXAMPLE' => $taxRole]);
    $journal = $this->ledger->documents->issueTaxed($request);
    $this->ledger->periods->close($this->book, $this->period, 'Close after posting');
    expect($this->ledger->documents->issueTaxed($request)->id)->toBe($journal->id);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $balances = collect($this->ledger->reports->trialBalance($snapshot))->keyBy('accountId');
    $taxAccount = $this->ledger->configurations->account($this->book, $configuration, $taxRole);
    expect($balances[$taxAccount]->closing->decimal())->toBe($expectedTax);
    expect($this->ledger->reports->controlReconciliation($snapshot, $this->ledger->configurations->account($this->book, $configuration, $controlRole))->decimal())->toBe('0.00');
    expect($this->ledger->reports->balanceSheet($snapshot)['difference']->decimal())->toBe('0.00');
    $this->storage->run(Permission::Read, function () use ($journal): void {
        $row = $this->storage->find('ledger_journals', $journal->id, $this->book);
        $evidence = json_decode($row->metadata, true, 512, JSON_THROW_ON_ERROR)['tax_document'];
        expect($evidence['lines'][0]['result']['net']['amount'])->toBe('150.00');
        expect($evidence['lines'][0]['rules'][0]['policy_version'])->toBe('example:v1');
    }, $this->book);
    $this->ledger->periods->reopen($this->book, $this->period, 'Correct posted example');
    $this->ledger->journals->reverse($this->book, $journal->id, 'correct-tax-document', '2026-01-02', 'Cancel document');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $balances = collect($this->ledger->reports->trialBalance($snapshot))->keyBy('accountId');
    expect($balances[$taxAccount]->closing->decimal())->toBe('0.00');
})->with([
    [DocumentKind::SalesInvoice, 'receivable', 'revenue', 'tax.output', '-24.00'],
    [DocumentKind::SalesCredit, 'receivable', 'revenue', 'tax.output', '24.00'],
    [DocumentKind::PurchaseBill, 'payable', 'cost', 'tax.input', '24.00'],
    [DocumentKind::PurchaseCredit, 'payable', 'cost', 'tax.input', '-24.00'],
]);

it('posts signed tax rounding only to an explicitly selected rounding account', function () {
    $configuration = taxDocumentConfiguration($this->ledger, $this->book, [
        new TaxRule('A', '0.2', '2026-01-01', null, 'example:v1'), new TaxRule('B', '0.2', '2026-01-01', null, 'example:v1'),
    ]);
    $party = $this->ledger->catalog->party($this->entity, 'customer', 'Customer');
    $obligations = [new Obligation($party, Money::of('0.04', $this->kes), '2026-01-31')];
    $lines = [new TaxLine('revenue', '0.03', '1', 'Rounding example', ['A', 'B'])];
    $before = $this->ledger->events->cutoff($this->book);
    try {
        $this->ledger->documents->issueTaxed(new TaxedDocumentRequest($this->book, 'no-rounding', '2026-01-01', new Source('tax.example', 'bad'),
            DocumentKind::SalesInvoice, $configuration, 'receivable', $obligations, $lines, ['A' => 'tax.output', 'B' => 'tax.output']));
        test()->fail('Rounding requires an explicit account.');
    } catch (LedgerException $exception) {
        expect($exception->getMessage())->toContain('explicit rounding');
    }
    expect($this->ledger->events->cutoff($this->book))->toBe($before);
    $this->ledger->documents->issueTaxed(new TaxedDocumentRequest($this->book, 'rounding', '2026-01-01', new Source('tax.example', 'good'),
        DocumentKind::SalesInvoice, $configuration, 'receivable', $obligations, $lines, ['A' => 'tax.output', 'B' => 'tax.output'], roundingRole: 'rounding'));
    $balances = collect($this->ledger->reports->trialBalance($this->ledger->reports->snapshot($this->book, '2026-01-31')))->keyBy('code');
    expect($balances['1100']->closing->decimal())->toBe('0.04');
    expect($balances['4000']->closing->decimal())->toBe('-0.03');
    expect($balances['2200']->closing->decimal())->toBe('-0.02');
    expect($balances['5999']->closing->decimal())->toBe('0.01');
});

it('retains inclusive discounted tax evidence and splits the gross among debtors', function () {
    $configuration = taxDocumentConfiguration($this->ledger, $this->book, [new TaxRule('EXAMPLE', '0.16', '2026-01-01', null, 'inclusive:v1', inclusive: true)]);
    $guardian = $this->ledger->catalog->party($this->entity, 'guardian', 'Guardian');
    $sponsor = $this->ledger->catalog->party($this->entity, 'sponsor', 'Sponsor');
    $this->ledger->documents->issueTaxed(new TaxedDocumentRequest($this->book, 'inclusive', '2026-01-01', new Source('tax.example', 'inclusive'), DocumentKind::SalesInvoice,
        $configuration, 'receivable', [new Obligation($guardian, Money::of('100', $this->kes), '2026-01-31'), new Obligation($sponsor, Money::of('56.60', $this->kes), '2026-02-28')],
        [new TaxLine('revenue', '116', '1.5', 'Inclusive discounted service', ['EXAMPLE'], '0.1')], ['EXAMPLE' => 'tax.output']));
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $balances = collect($this->ledger->reports->trialBalance($snapshot))->keyBy('code');
    expect($balances['4000']->closing->decimal())->toBe('-135.00');
    expect($balances['2200']->closing->decimal())->toBe('-21.60');
    expect($this->ledger->reports->openItems($snapshot, $sponsor)[0]->outstanding->decimal())->toBe('56.60');
});

it('posts foreign split-obligation rounding as an explicit functional adjustment', function () {
    $configuration = taxDocumentConfiguration($this->ledger, $this->book, [], foreignControl: true);
    $usd = Currency::iso('USD');
    $first = $this->ledger->catalog->party($this->entity, 'first', 'First debtor');
    $second = $this->ledger->catalog->party($this->entity, 'second', 'Second debtor');
    $rate = new ExchangeRate($usd, $this->kes, '1.5', '2026-01-01', 'rounding-example', Rounding::HalfEven);
    $this->ledger->documents->issueTaxed(new TaxedDocumentRequest($this->book, 'foreign', '2026-01-01', new Source('tax.example', 'foreign'), DocumentKind::SalesInvoice,
        $configuration, 'receivable', [new Obligation($first, Money::of('0.01', $usd), '2026-01-31'), new Obligation($second, Money::of('0.01', $usd), '2026-01-31')],
        [new TaxLine('revenue', '0.02', '1', 'Foreign split obligation')], roundingRole: 'rounding', exchangeRate: $rate));
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $balances = collect($this->ledger->reports->trialBalance($snapshot))->keyBy('code');
    expect($balances['1100']->closing->decimal())->toBe('0.04');
    expect($balances['4000']->closing->decimal())->toBe('-0.03');
    expect($balances['5999']->closing->decimal())->toBe('-0.01');
    expect($this->ledger->reports->controlReconciliation($snapshot, $this->ledger->configurations->account($this->book, $configuration, 'receivable'))->decimal())->toBe('0.00');
});
