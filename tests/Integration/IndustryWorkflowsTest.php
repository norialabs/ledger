<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Noria\Ledger\Access\Authorizer;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Access\Scope;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\AllocationRequest;
use Noria\Ledger\Accounting\Journal;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Accounting\Source;
use Noria\Ledger\Accounting\TreatmentRequest;
use Noria\Ledger\Configuration\Pack;
use Noria\Ledger\Documents\Charge;
use Noria\Ledger\Documents\DocumentKind;
use Noria\Ledger\Documents\DocumentRequest;
use Noria\Ledger\Documents\Obligation;
use Noria\Ledger\Documents\PaymentRequest;
use Noria\Ledger\Documents\TaxedDocumentRequest;
use Noria\Ledger\Documents\TaxLine;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Ledger;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Money\Rounding;
use Noria\Ledger\Reporting\StatementColumn;
use Noria\Ledger\Reporting\StatementDefinition;
use Noria\Ledger\Session;
use Noria\Ledger\Tax\Kenya;
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

function taxTreatmentConfiguration(Session $ledger, string $book, string $version = '1'): string
{
    return $ledger->configurations->install($book, new Pack('example-localization', $version, accounts: [
        ['code' => '1100', 'name' => 'Receivables', 'type' => 'asset', 'control' => true],
        ['code' => '1200', 'name' => 'Recoverable input tax', 'type' => 'asset'],
        ['code' => '1210', 'name' => 'Withholding credits', 'type' => 'asset'],
        ['code' => '2000', 'name' => 'Payables', 'type' => 'liability', 'control' => true],
        ['code' => '2210', 'name' => 'Withholding payable', 'type' => 'liability'],
        ['code' => '2220', 'name' => 'Reverse-charge tax payable', 'type' => 'liability'],
        ['code' => '5000', 'name' => 'Purchases', 'type' => 'expense'],
        ['code' => '5200', 'name' => 'Irrecoverable tax', 'type' => 'expense'],
    ], roles: ['cash' => '1000', 'receivable' => '1100', 'payable' => '2000', 'tax.input' => '1200',
        'tax.withholding.receivable' => '1210', 'tax.withholding.payable' => '2210', 'tax.reverse_charge.payable' => '2220', 'tax.irrecoverable' => '5200'],
        taxRules: [new TaxRule('EXAMPLE-WHT', '0.05', '2026-01-01', '2026-12-31', 'example-wht:1'), new TaxRule('EXAMPLE-RC', '0.16', '2026-01-01', '2026-12-31', 'example-rc:1')],
        statements: [new StatementDefinition('Illustrative tax worksheet', $version, [
            'reverse_charge' => ['label' => 'Self-assessed tax', 'accounts' => ['2220'], 'sign' => 'credit', 'include_closing' => false],
            'recoverable' => ['label' => 'Recoverable input', 'accounts' => ['1200'], 'include_closing' => false],
            'net' => ['label' => 'Net tax', 'terms' => ['reverse_charge' => '1', 'recoverable' => '-1']],
            'withholding' => ['label' => 'Withholding payable', 'accounts' => ['2210'], 'sign' => 'credit', 'include_closing' => false],
        ])], validFrom: '2026-01-01', validUntil: '2026-12-31'));
}

/** @param array<string, mixed> $inputs */
function taxTreatmentRequest(string $book, string $configuration, string $key, array $inputs, string $date = '2026-01-31'): TreatmentRequest
{
    return new TreatmentRequest($book, $key, $date, new Source('tax.treatment.example', $key), 'ledger.tax', '1', $configuration,
        [...$inputs, 'rounding' => 'half_even', 'policy_version' => 'example-eligibility:1', 'basis_reference' => 'approved-tax-category:42']);
}

it('settles the full supplier obligation while separating cash and withholding liability', function () {
    $configuration = taxTreatmentConfiguration($this->ledger, $this->book);
    $supplier = $this->ledger->catalog->party($this->entity, 'withheld-supplier', 'Supplier');
    $payable = $this->ledger->configurations->account($this->book, $configuration, 'payable');
    $expense = $this->ledger->catalog->account($this->book, '5100', 'Services', AccountType::Expense);
    $bill = $this->ledger->documents->issue(new DocumentRequest($this->book, 'withheld-bill', '2026-01-01', new Source('example.bill', 'W-1'), DocumentKind::PurchaseBill,
        $payable, [new Obligation($supplier, Money::of('1160', $this->kes), '2026-01-31')], [new Charge($expense, Money::of('1160', $this->kes), 'Services and separately determined tax')]));
    $request = taxTreatmentRequest($this->book, $configuration, 'withheld-payment', [
        'operation' => 'withholding.payment', 'party_id' => $supplier, 'settlement' => '1160', 'tax_base' => '1000', 'tax_code' => 'EXAMPLE-WHT', 'cash_flow' => 'operating',
    ]);
    $payment = $this->ledger->treatments->post($request);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $items = $this->ledger->reports->openItems($snapshot, $supplier);
    $debit = collect($items)->first(fn ($item) => $item->journalId === $payment->id);
    $credit = collect($items)->first(fn ($item) => $item->journalId === $bill->id);
    $allocation = $this->ledger->settlements->allocate(new AllocationRequest($this->book, 'allocate-withheld', $debit->id, $credit->id, Money::of('1160', $this->kes), '2026-01-31', 'Full supplier settlement'));
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $balances = collect($this->ledger->reports->trialBalance($snapshot))->keyBy('code');
    expect($balances['1000']->closing->decimal())->toBe('-1110.00')
        ->and($balances['2210']->closing->decimal())->toBe('-50.00')
        ->and($balances['2000']->closing->decimal())->toBe('0.00')
        ->and($this->ledger->reports->openItems($snapshot, $supplier))->toBe([])
        ->and($this->ledger->reports->cashFlow($snapshot, '2026-01-01')->operating->decimal())->toBe('-1110.00');
    $this->ledger->settlements->release($this->book, $allocation, 'release-withheld', '2026-02-01', 'Correct withholding');
    $this->ledger->journals->reverse($this->book, $payment->id, 'reverse-withheld', '2026-02-01', 'Correct withholding');
    expect($this->ledger->reports->openItems($this->ledger->reports->snapshot($this->book, '2026-02-01'), $supplier)[0]->outstanding->decimal())->toBe('1160.00');
});

it('records customer withholding credits without counting them as cash or reducing revenue', function () {
    $configuration = taxTreatmentConfiguration($this->ledger, $this->book);
    $customer = $this->ledger->catalog->party($this->entity, 'withheld-customer', 'Customer');
    $receivable = $this->ledger->configurations->account($this->book, $configuration, 'receivable');
    $this->ledger->documents->issue(new DocumentRequest($this->book, 'withheld-invoice', '2026-01-01', new Source('example.invoice', 'W-1'), DocumentKind::SalesInvoice,
        $receivable, [new Obligation($customer, Money::of('1000', $this->kes), '2026-01-31')], [new Charge($this->income, Money::of('1000', $this->kes), 'Services')]));
    $request = taxTreatmentRequest($this->book, $configuration, 'withheld-receipt', [
        'operation' => 'withholding.receipt', 'party_id' => $customer, 'settlement' => '1000', 'tax_base' => '1000', 'tax_code' => 'EXAMPLE-WHT', 'cash_flow' => 'operating',
    ]);
    $journal = $this->ledger->treatments->post($request);
    $this->ledger->periods->close($this->book, $this->period, 'Close receipt period');
    expect($this->ledger->treatments->post($request)->id)->toBe($journal->id);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $balances = collect($this->ledger->reports->trialBalance($snapshot))->keyBy('code');
    expect($balances['1000']->closing->decimal())->toBe('950.00')
        ->and($balances['1210']->closing->decimal())->toBe('50.00')
        ->and($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['income']->decimal())->toBe('1000.00')
        ->and($this->ledger->reports->controlReconciliation($snapshot, $receivable)->decimal())->toBe('0.00');
});

it('self-assesses reverse charge with explicit partial recoverability and no duplicate supplier debt', function (string $fraction, string $recoverable, string $expense) {
    $configuration = taxTreatmentConfiguration($this->ledger, $this->book);
    $journal = $this->ledger->treatments->post(taxTreatmentRequest($this->book, $configuration, 'reverse-charge', [
        'operation' => 'reverse_charge', 'tax_code' => 'EXAMPLE-RC', 'tax_base' => '1000', 'recoverable_fraction' => $fraction,
    ]));
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $balances = collect($this->ledger->reports->trialBalance($snapshot))->keyBy('code');
    expect($balances['1200']->closing->decimal())->toBe($recoverable)
        ->and($balances['5200']->closing->decimal())->toBe($expense)
        ->and($balances['2220']->closing->decimal())->toBe('-160.00')
        ->and($balances['2000']->closing->decimal())->toBe('0.00')
        ->and($this->ledger->reports->openItems($snapshot))->toBe([])
        ->and($this->ledger->reports->balanceSheet($snapshot)['difference']->decimal())->toBe('0.00');
    $this->ledger->journals->reverse($this->book, $journal->id, 'rc-correction', '2026-02-01', 'Cancel imported service assessment');
    $balances = collect($this->ledger->reports->trialBalance($this->ledger->reports->snapshot($this->book, '2026-02-01')))->keyBy('code');
    expect($balances['2220']->closing->decimal())->toBe('0.00');
})->with([['0', '0.00', '160.00'], ['0.75', '120.00', '40.00'], ['1', '160.00', '0.00']]);

it('saves a configuration-pinned return worksheet with immutable cutoffs and correction continuity', function () {
    $configuration = taxTreatmentConfiguration($this->ledger, $this->book);
    $journal = $this->ledger->treatments->post(taxTreatmentRequest($this->book, $configuration, 'rc-return', [
        'operation' => 'reverse_charge', 'tax_code' => 'EXAMPLE-RC', 'tax_base' => '1000', 'recoverable_fraction' => '0.75',
    ]));
    $columns = [new StatementColumn('current', 'January 2026', $this->ledger->reports->snapshot($this->book, '2026-01-31'), '2026-01-01')];
    $id = $this->ledger->reports->saveConfiguredStatement($this->book, 'january-tax', $configuration, 'Illustrative tax worksheet', $columns);
    $evidence = $this->ledger->reports->statementEvidence($this->book, $id);
    expect($evidence['result']['configuration']['id'])->toBe($configuration)
        ->and($evidence['result']['configuration']['content_hash'])->toBe($this->ledger->configurations->get($this->book, $configuration)['content_hash'])
        ->and($evidence['result']['rows']['net']['values']['current']['amount'])->toBe('40.00')
        ->and(hash('sha256', $evidence['result_json']))->toBe($evidence['result_hash']);
    $this->ledger->journals->reverse($this->book, $journal->id, 'rc-backdated-correction', '2026-01-31', 'Amend January assessment');
    expect($this->ledger->reports->saveConfiguredStatement($this->book, 'january-tax', $configuration, 'Illustrative tax worksheet', $columns))->toBe($id)
        ->and($this->ledger->reports->statementEvidence($this->book, $id))->toBe($evidence);
    $amended = $this->ledger->reports->saveConfiguredStatement($this->book, 'january-tax-amended', $configuration, 'Illustrative tax worksheet', [
        new StatementColumn('current', 'January 2026', $this->ledger->reports->snapshot($this->book, '2026-01-31'), '2026-01-01'),
    ]);
    expect($this->ledger->reports->statementEvidence($this->book, $amended)['result']['rows']['net']['values']['current']['amount'])->toBe('0.00');
});

it('rejects altered worksheet definitions claiming a pinned localization configuration', function () {
    $configuration = taxTreatmentConfiguration($this->ledger, $this->book);
    $definition = new StatementDefinition('Illustrative tax worksheet', '1', ['false' => ['label' => 'False zero', 'accounts' => []]]);
    $this->ledger->reports->saveStatement($this->book, 'forged-return', $definition, [
        new StatementColumn('current', 'January', $this->ledger->reports->snapshot($this->book, '2026-01-31'), '2026-01-01'),
    ], $configuration);
})->throws(LedgerException::class, 'differs from its pinned configuration');

it('rolls back invalid localization policy inputs before posting cash or tax', function (string $case) {
    $configuration = taxTreatmentConfiguration($this->ledger, $this->book);
    $before = $this->ledger->events->cutoff($this->book);
    $inputs = ['operation' => 'reverse_charge', 'tax_code' => $case === 'code' ? 'UNKNOWN' : 'EXAMPLE-RC', 'tax_base' => '1000', 'recoverable_fraction' => $case === 'recovery' ? '1.01' : '1'];
    try {
        $this->ledger->treatments->post(taxTreatmentRequest($this->book, $configuration, 'invalid-tax-treatment', $inputs));
        test()->fail('Invalid localization policy must fail.');
    } catch (LedgerException $exception) {
        expect($exception->getMessage())->toContain($case === 'code' ? 'configured exclusive' : 'between zero and one');
    }
    expect($this->ledger->events->cutoff($this->book))->toBe($before);
})->with(['code', 'recovery']);

it('installs the Kenya VAT pack with separate pending import tax and country-pinned worksheet evidence', function () {
    $entity = $this->ledger->catalog->entity('Kenyan VAT taxpayer', 'KE');
    $book = $this->ledger->catalog->book($entity, 'VAT book', $this->kes);
    $this->ledger->periods->open($book, '2026', '2026-01-01', '2026-12-31');
    $definitions = [
        'cash' => ['1000', 'Bank', AccountType::Asset, false, 'cash'],
        'receivable' => ['1100', 'Receivables', AccountType::Asset, true, null],
        'payable' => ['2000', 'Payables', AccountType::Liability, true, null],
        'tax.output' => ['2200', 'Output VAT', AccountType::Liability, false, null],
        'tax.input' => ['1200', 'Eligible input VAT', AccountType::Asset, false, null],
        'tax.irrecoverable' => ['5200', 'Irrecoverable tax', AccountType::Expense, false, null],
        'tax.reverse_charge.input' => ['1220', 'Import VAT pending payment', AccountType::Asset, false, null],
        'tax.reverse_charge.payable' => ['2220', 'Import VAT payable', AccountType::Liability, false, null],
        'tax.withholding.receivable' => ['1230', 'Withholding VAT credits', AccountType::Asset, false, null],
        'tax.withholding.payable' => ['2230', 'VAT withheld as agent', AccountType::Liability, false, null],
    ];
    $roles = [];
    foreach ($definitions as $role => [$code, $name, $type, $control, $cashFlow]) {
        $this->ledger->catalog->account($book, $code, $name, $type, control: $control, cashFlow: $cashFlow);
        $roles[$role] = $code;
    }
    $pack = Kenya::vatPack('2026.1', '2026-01-01', '2026-12-31', 'organization-vat-policy:1', $roles);
    $configuration = $this->ledger->configurations->install($book, $pack);
    expect($this->ledger->configurations->install($book, $pack))->toBe($configuration);
    $rules = $this->ledger->configurations->taxRules($book, $configuration, '2026-01-31');
    expect(array_map(fn (TaxRule $rule) => $rule->rate, $rules))->toBe(['0.16', '0', '0.02']);
    $this->ledger->treatments->post(taxTreatmentRequest($book, $configuration, 'ke-import-vat', [
        'operation' => 'reverse_charge', 'tax_code' => 'KE-VAT-STANDARD', 'tax_base' => '1000', 'recoverable_fraction' => '1',
    ]));
    $report = $this->ledger->reports->saveConfiguredStatement($book, 'ke-vat-january', $configuration, 'Kenya VAT ledger worksheet', [
        new StatementColumn('current', 'January 2026', $this->ledger->reports->snapshot($book, '2026-01-31'), '2026-01-01'),
    ]);
    $rows = $this->ledger->reports->statementEvidence($book, $report)['result']['rows'];
    expect($rows['input']['values']['current']['amount'])->toBe('0.00')
        ->and($rows['net_movement']['values']['current']['amount'])->toBe('0.00')
        ->and($rows['import_assessed']['values']['current']['amount'])->toBe('160.00')
        ->and($rows['import_pending']['values']['current']['amount'])->toBe('160.00');
    $definition = $this->ledger->configurations->get($book, $configuration)['definition'];
    expect($definition['country'])->toBe('KE')
        ->and($definition['settings']['exempt_is_zero_rated'])->toBeFalse()
        ->and($definition['settings']['statutory_submission_format'])->toBeNull();
    try {
        $this->ledger->treatments->post(taxTreatmentRequest($book, $configuration, 'ke-wrong-tax-kind', [
            'operation' => 'reverse_charge', 'tax_code' => 'KE-WH-VAT', 'tax_base' => '1000', 'recoverable_fraction' => '1',
        ]));
        test()->fail('Withholding VAT is not the imported-service assessment rate.');
    } catch (LedgerException $exception) {
        expect($exception->getMessage())->toContain('not configured for this treatment');
    }
});

function taxRegisterConfiguration(Session $ledger, string $book): string
{
    taxTreatmentConfiguration($ledger, $book);
    $policy = Kenya::vatReturnPolicy();
    $policy['codes'] = ['EXAMPLE-RC' => ['label' => 'Illustrative standard tax'], 'EXAMPLE-WHT' => ['label' => 'Illustrative withholding'], 'ZERO' => ['label' => 'Zero-rated']];

    return $ledger->configurations->install($book, new Pack('return-fixture', '1', accounts: [
        ['code' => '2200', 'name' => 'Output tax', 'type' => 'liability'], ['code' => '1220', 'name' => 'Pending import input tax', 'type' => 'asset'],
    ], roles: ['cash' => '1000', 'receivable' => '1100', 'payable' => '2000', 'revenue' => '4000', 'cost' => '5000', 'tax.output' => '2200', 'tax.input' => '1200',
        'tax.reverse_charge.input' => '1220', 'tax.reverse_charge.payable' => '2220', 'tax.withholding.receivable' => '1210', 'tax.withholding.payable' => '2210', 'tax.irrecoverable' => '5200'],
        taxRules: [new TaxRule('EXAMPLE-RC', '0.16', '2026-01-01', '2026-12-31', 'example:1'), new TaxRule('EXAMPLE-WHT', '0.05', '2026-01-01', '2026-12-31', 'example:1'),
            new TaxRule('ZERO', '0', '2026-01-01', '2026-12-31', 'example-zero:1')],
        settings: ['tax_returns' => ['vat' => $policy], 'import_input_requires_payment_and_claim_review' => true], validFrom: '2026-01-01', validUntil: '2026-12-31'));
}

function taxRegisterDocument(Session $ledger, string $book, string $configuration, string $party, string $key, DocumentKind $kind, string $price = '100', string $date = '2026-01-15', string $code = 'EXAMPLE-RC'): Journal
{
    $purchase = in_array($kind, [DocumentKind::PurchaseBill, DocumentKind::PurchaseCredit], true);
    $amount = Money::of($price, Currency::iso('KES'))->multipliedBy($code === 'ZERO' ? '1' : '1.16', Rounding::HalfEven);

    return $ledger->documents->issueTaxed(new TaxedDocumentRequest($book, $key, $date, new Source('return-fixture', $key), $kind, $configuration,
        $purchase ? 'payable' : 'receivable', [new Obligation($party, $amount, $date)], [new TaxLine($purchase ? 'cost' : 'revenue', $price, '1', 'Reviewed source line', [$code])],
        [$code => $purchase ? 'tax.input' : 'tax.output']));
}

it('derives an invoice-level tax register and finalizes a reconciled immutable return', function () {
    $configuration = taxRegisterConfiguration($this->ledger, $this->book);
    $party = $this->ledger->catalog->party($this->entity, 'return-party', 'Tax counterparty');
    taxRegisterDocument($this->ledger, $this->book, $configuration, $party, 'return-sale', DocumentKind::SalesInvoice, '200');
    $purchase = taxRegisterDocument($this->ledger, $this->book, $configuration, $party, 'return-purchase', DocumentKind::PurchaseBill);
    $decision = $this->ledger->taxes->decide($this->book, 'allow-input', $configuration, 'vat', '2026-01-31', $purchase->id, 1, 'EXAMPLE-RC', 'claim', '0.75', 'Reviewed recoverability', ['invoice_evidence' => 'supplier-42']);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $register = $this->ledger->taxes->register($snapshot, '2026-01-01');
    expect($register)->toHaveCount(2);
    $preview = $this->ledger->taxes->preview($snapshot, $configuration, 'vat', '2026-01-01');
    expect($preview['totals']['output_tax'])->toBe('3200')->and($preview['totals']['input_claimed'])->toBe('1200')
        ->and($preview['net_minor'])->toBe('2000')->and($preview['unreviewed'])->toBe([])->and($preview['unreconciled'])->toBe([]);
    $return = $this->ledger->taxes->prepare($snapshot, 'january-return', $configuration, 'vat', '2026-01-01');
    $before = $this->ledger->taxes->evidence($this->book, $return);
    expect(hash('sha256', $before['result_json']))->toBe($before['result_hash'])->and($before['status'])->toBe('prepared');
    $this->ledger->taxes->finalize($this->book, $return, 'finalize-january', '2026-02-01', 'Reviewed return reconciliation');
    $this->ledger->taxes->file($this->book, $return, 'record-filing', '2026-02-02', 'external-receipt-42', 'Record external filing evidence');
    expect($this->ledger->taxes->evidence($this->book, $return)['status'])->toBe('filed')
        ->and($this->ledger->taxes->prepare($snapshot, 'january-return', $configuration, 'vat', '2026-01-01'))->toBe($return)
        ->and($this->ledger->taxes->decide($this->book, 'allow-input', $configuration, 'vat', '2026-01-31', $purchase->id, 1, 'EXAMPLE-RC', 'claim', '0.75', 'Reviewed recoverability', ['invoice_evidence' => 'supplier-42']))->toBe($decision);
});

it('moves deferred input claims between periods without claiming the invoice twice', function () {
    $configuration = taxRegisterConfiguration($this->ledger, $this->book);
    $party = $this->ledger->catalog->party($this->entity, 'deferred-input', 'Supplier');
    $invoice = taxRegisterDocument($this->ledger, $this->book, $configuration, $party, 'deferred-bill', DocumentKind::PurchaseBill);
    $this->ledger->taxes->decide($this->book, 'defer-january', $configuration, 'vat', '2026-01-31', $invoice->id, 1, 'EXAMPLE-RC', 'defer', '0', 'Await supporting documents');
    $january = $this->ledger->taxes->prepare($this->ledger->reports->snapshot($this->book, '2026-01-31'), 'deferred-january', $configuration, 'vat', '2026-01-01');
    $this->ledger->taxes->finalize($this->book, $january, 'approve-deferred-january', '2026-02-01', 'Reviewed deferral');
    $this->ledger->taxes->file($this->book, $january, 'file-deferred-january', '2026-02-01', 'JAN-DEFERRED', 'External filing receipt');
    $this->ledger->taxes->decide($this->book, 'claim-half-february', $configuration, 'vat', '2026-02-28', $invoice->id, 1, 'EXAMPLE-RC', 'claim', '0.5', 'Half now eligible');
    $february = $this->ledger->taxes->prepare($this->ledger->reports->snapshot($this->book, '2026-02-28'), 'deferred-february', $configuration, 'vat', '2026-02-01', priorPeriodId: $january);
    expect($this->ledger->taxes->evidence($this->book, $february)['result']['totals']['input_claimed'])->toBe('800');
    $this->ledger->taxes->finalize($this->book, $february, 'approve-deferred-february', '2026-03-01', 'Reviewed half claim');
    $this->ledger->taxes->file($this->book, $february, 'file-deferred-february', '2026-03-01', 'FEB-DEFERRED', 'External filing receipt');
    $this->ledger->taxes->decide($this->book, 'claim-rest-march', $configuration, 'vat', '2026-03-31', $invoice->id, 1, 'EXAMPLE-RC', 'claim', '1', 'Remaining eligibility confirmed');
    $march = $this->ledger->taxes->prepare($this->ledger->reports->snapshot($this->book, '2026-03-31'), 'deferred-march', $configuration, 'vat', '2026-03-01', priorPeriodId: $february);
    $result = $this->ledger->taxes->evidence($this->book, $march)['result'];
    expect($result['entries'])->toBe([])->and($result['totals']['input_claimed'])->toBe('800')
        ->and($result['opening_credit_minor'])->toBe('800')->and($result['credit_carried_minor'])->toBe('1600');
    $this->ledger->taxes->finalize($this->book, $march, 'approve-deferred-march', '2026-04-01', 'Reviewed remaining claim');
});

it('reverses previously claimed input in the correction period and preserves earlier return evidence', function () {
    $configuration = taxRegisterConfiguration($this->ledger, $this->book);
    $party = $this->ledger->catalog->party($this->entity, 'corrected-tax-input', 'Supplier');
    $invoice = taxRegisterDocument($this->ledger, $this->book, $configuration, $party, 'corrected-bill', DocumentKind::PurchaseBill);
    $this->ledger->taxes->decide($this->book, 'claim-corrected-bill', $configuration, 'vat', '2026-01-31', $invoice->id, 1, 'EXAMPLE-RC', 'claim', '0.75', 'Reviewed initial eligibility');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $return = $this->ledger->taxes->prepare($snapshot, 'before-correction', $configuration, 'vat', '2026-01-01');
    $before = $this->ledger->taxes->evidence($this->book, $return);
    $reversal = $this->ledger->journals->reverse($this->book, $invoice->id, 'correct-bill', '2026-02-01', 'Cancel supplier bill');
    $february = $this->ledger->taxes->preview($this->ledger->reports->snapshot($this->book, '2026-02-28'), $configuration, 'vat', '2026-02-01');
    expect($february['totals']['input_claimed'])->toBe('-1200')->and($february['net_minor'])->toBe('1200')
        ->and($february['entries'][0]['origin_id'])->toBe($invoice->id)->and($february['entries'][0]['journal_id'])->toBe($reversal->id)
        ->and($this->ledger->taxes->evidence($this->book, $return))->toEqual($before);
    $march = $this->ledger->taxes->preview($this->ledger->reports->snapshot($this->book, '2026-03-31'), $configuration, 'vat', '2026-03-01');
    expect($march['totals']['input_claimed'])->toBe('0');
});

it('requires reviewed tax mappings and per-posting reconciliation even for offsetting manual activity', function (string $case) {
    $configuration = taxRegisterConfiguration($this->ledger, $this->book);
    $party = $this->ledger->catalog->party($this->entity, 'tax-review-case', 'Counterparty');
    if ($case === 'unreviewed') {
        taxRegisterDocument($this->ledger, $this->book, $configuration, $party, 'unreviewed-input', DocumentKind::PurchaseBill);
    } elseif ($case === 'unmapped') {
        $this->ledger->documents->issueTaxed(new TaxedDocumentRequest($this->book, 'unmapped-exempt', '2026-01-15', new Source('return-fixture', 'exempt'), DocumentKind::SalesInvoice,
            $configuration, 'receivable', [new Obligation($party, Money::of('100', $this->kes), '2026-01-31')], [new TaxLine('revenue', '100', '1', 'Unclassified untaxed supply')]));
    } else {
        $tax = $this->ledger->configurations->account($this->book, $configuration, 'tax.output');
        foreach ([Side::Debit, Side::Credit] as $index => $side) {
            $this->ledger->journals->post(new JournalRequest($this->book, 'manual-offset-'.$index, '2026-01-31', 'Unclassified offsetting tax entry', [
                new Posting($tax, $side, Money::of('1', $this->kes)), new Posting($this->cash, $side->opposite(), Money::of('1', $this->kes)),
            ]));
        }
    }
    $return = $this->ledger->taxes->prepare($this->ledger->reports->snapshot($this->book, '2026-01-31'), 'unresolved-return', $configuration, 'vat', '2026-01-01');
    $this->ledger->taxes->finalize($this->book, $return, 'reject-unresolved', '2026-02-01', 'Attempt incomplete return');
})->with(['unreviewed', 'unmapped', 'unreconciled'])->throws(QueryException::class, 'unresolved claims, mappings or ledger reconciliation');

it('reconciles tax settlements without treating payments as reduced output-tax liability for the return', function () {
    $configuration = taxRegisterConfiguration($this->ledger, $this->book);
    $party = $this->ledger->catalog->party($this->entity, 'settled-tax-party', 'Customer');
    taxRegisterDocument($this->ledger, $this->book, $configuration, $party, 'settled-tax-sale', DocumentKind::SalesInvoice, '200');
    $tax = $this->ledger->configurations->account($this->book, $configuration, 'tax.output');
    $payment = $this->ledger->journals->post(new JournalRequest($this->book, 'pay-tax', '2026-01-31', 'Tax payment', [
        new Posting($tax, Side::Debit, Money::of('10', $this->kes)), new Posting($this->cash, Side::Credit, Money::of('10', $this->kes)),
    ]));
    $this->ledger->taxes->reconcile($this->book, 'classify-payment', $configuration, 'vat', '2026-01-31', $payment->id, 1, 'settlement', 'Reviewed payment evidence', ['receipt' => 'tax-payment-42']);
    $return = $this->ledger->taxes->prepare($this->ledger->reports->snapshot($this->book, '2026-01-31'), 'settled-return', $configuration, 'vat', '2026-01-01');
    $result = $this->ledger->taxes->evidence($this->book, $return)['result'];
    expect($result['net_minor'])->toBe('3200')->and($result['unreconciled'])->toBe([])
        ->and($result['reconciliation_decisions'][0]['classification'])->toBe('settlement');
    $this->ledger->taxes->finalize($this->book, $return, 'approve-settled-return', '2026-02-01', 'Reviewed liability reconciliation');
});

it('requires explicit linked amendments after backdated tax changes and retains filed evidence', function () {
    $configuration = taxRegisterConfiguration($this->ledger, $this->book);
    $party = $this->ledger->catalog->party($this->entity, 'tax-amendment-party', 'Customer');
    taxRegisterDocument($this->ledger, $this->book, $configuration, $party, 'original-tax-sale', DocumentKind::SalesInvoice);
    $original = $this->ledger->taxes->prepare($this->ledger->reports->snapshot($this->book, '2026-01-31'), 'original-tax-return', $configuration, 'vat', '2026-01-01');
    $this->ledger->taxes->finalize($this->book, $original, 'finalize-original-tax', '2026-02-01', 'Original approval');
    $this->ledger->taxes->file($this->book, $original, 'file-original-tax', '2026-02-01', 'ORIGINAL-RECEIPT', 'Original external filing');
    $before = $this->ledger->taxes->evidence($this->book, $original);
    taxRegisterDocument($this->ledger, $this->book, $configuration, $party, 'late-tax-credit', DocumentKind::SalesCredit, '50', '2026-01-31');
    $amended = $this->ledger->taxes->prepare($this->ledger->reports->snapshot($this->book, '2026-01-31'), 'amended-tax-return', $configuration, 'vat', '2026-01-01', previousId: $original);
    expect($this->ledger->taxes->evidence($this->book, $amended)['result']['net_minor'])->toBe('800')
        ->and($this->ledger->taxes->evidence($this->book, $amended)['definition']->previous_id)->toBe($original)
        ->and($this->ledger->taxes->evidence($this->book, $original))->toEqual($before);
    $this->ledger->taxes->finalize($this->book, $amended, 'finalize-tax-amendment', '2026-02-02', 'Reviewed amendment');
    $this->ledger->taxes->file($this->book, $amended, 'file-tax-amendment', '2026-02-02', 'AMENDED-RECEIPT', 'Amended external filing');
});

it('rejects stale finalization while preserving external filing receipts for the approved version', function (bool $alreadyFinalized) {
    $configuration = taxRegisterConfiguration($this->ledger, $this->book);
    $party = $this->ledger->catalog->party($this->entity, 'stale-tax-party', 'Customer');
    taxRegisterDocument($this->ledger, $this->book, $configuration, $party, 'stale-tax-sale', DocumentKind::SalesInvoice);
    $return = $this->ledger->taxes->prepare($this->ledger->reports->snapshot($this->book, '2026-01-31'), 'stale-tax-return', $configuration, 'vat', '2026-01-01');
    if ($alreadyFinalized) {
        $this->ledger->taxes->finalize($this->book, $return, 'finalized-before-change', '2026-02-01', 'Original review');
    }
    taxRegisterDocument($this->ledger, $this->book, $configuration, $party, 'backdated-tax-sale', DocumentKind::SalesInvoice, '100', '2026-01-31');
    if ($alreadyFinalized) {
        $this->ledger->taxes->file($this->book, $return, 'file-stale-return', '2026-02-02', 'STALE-RECEIPT', 'Record receipt for the approved version');
        expect($this->ledger->taxes->evidence($this->book, $return)['status'])->toBe('filed')
            ->and($this->ledger->taxes->evidence($this->book, $return)['result']['net_minor'])->toBe('1600')
            ->and($this->ledger->taxes->preview($this->ledger->reports->snapshot($this->book, '2026-01-31'), $configuration, 'vat', '2026-01-01')['net_minor'])->toBe('3200');
    } else {
        try {
            $this->ledger->taxes->finalize($this->book, $return, 'finalize-stale-return', '2026-02-01', 'Attempt outdated approval');
            test()->fail('Stale prepared figures require a linked replacement.');
        } catch (QueryException $exception) {
            expect($exception->getMessage())->toContain('stale; prepare a new linked version');
        }
    }
})->with([false, true]);

it('requires an exclusive unreversed tax payment before claiming pending imported input VAT', function () {
    $configuration = taxRegisterConfiguration($this->ledger, $this->book);
    $assessment = $this->ledger->treatments->post(taxTreatmentRequest($this->book, $configuration, 'import-tax-assessment', [
        'operation' => 'reverse_charge', 'tax_code' => 'EXAMPLE-RC', 'tax_base' => '1000', 'recoverable_fraction' => '0.75',
    ]));
    try {
        $this->ledger->taxes->decide($this->book, 'unpaid-import-claim', $configuration, 'vat', '2026-01-31', $assessment->id, 2, 'EXAMPLE-RC', 'claim', '1', 'Attempt unpaid claim');
        test()->fail('Pending imported input requires actual payment evidence.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('exclusive unreversed full tax payment');
    }
    $tax = $this->ledger->configurations->account($this->book, $configuration, 'tax.reverse_charge.payable');
    $payment = $this->ledger->journals->post(new JournalRequest($this->book, 'pay-import-tax', '2026-01-31', 'Pay full import tax', [
        new Posting($tax, Side::Debit, Money::of('160', $this->kes)), new Posting($this->cash, Side::Credit, Money::of('160', $this->kes)),
    ]));
    $this->ledger->taxes->decide($this->book, 'paid-import-claim', $configuration, 'vat', '2026-01-31', $assessment->id, 2, 'EXAMPLE-RC', 'claim', '1', 'Reviewed paid input claim', ['payment_journal_id' => $payment->id]);
    $this->ledger->taxes->reconcile($this->book, 'import-payment-classification', $configuration, 'vat', '2026-01-31', $payment->id, 1, 'settlement', 'Classify import tax payment');
    $return = $this->ledger->taxes->prepare($this->ledger->reports->snapshot($this->book, '2026-01-31'), 'paid-import-return', $configuration, 'vat', '2026-01-01');
    $result = $this->ledger->taxes->evidence($this->book, $return)['result'];
    expect($result['totals']['input_claimed'])->toBe('12000')->and($result['totals']['reverse_charge_tax'])->toBe('16000')
        ->and($result['net_minor'])->toBe('-12000')->and($result['invalid_claims'])->toBe([]);
    $this->ledger->taxes->finalize($this->book, $return, 'approve-paid-import-return', '2026-02-01', 'Review paid import claim');
    $other = $this->ledger->treatments->post(taxTreatmentRequest($this->book, $configuration, 'second-import-assessment', [
        'operation' => 'reverse_charge', 'tax_code' => 'EXAMPLE-RC', 'tax_base' => '1000', 'recoverable_fraction' => '1',
    ]));
    $this->ledger->taxes->decide($this->book, 'reused-import-payment', $configuration, 'vat', '2026-01-31', $other->id, 2, 'EXAMPLE-RC', 'claim', '1', 'Attempt reused payment', ['payment_journal_id' => $payment->id]);
})->throws(QueryException::class, 'exclusive unreversed full tax payment');

it('derives tax register functional amounts from the original foreign calculation and paginates without losing lines', function () {
    $configuration = taxDocumentConfiguration($this->ledger, $this->book, [new TaxRule('EXAMPLE', '0.16', '2026-01-01', null, 'example:1')], foreignControl: true);
    $party = $this->ledger->catalog->party($this->entity, 'foreign-tax-register', 'Foreign customer');
    $usd = Currency::iso('USD');
    $this->ledger->documents->issueTaxed(new TaxedDocumentRequest($this->book, 'foreign-tax-register', '2026-01-15', new Source('tax-register', 'USD-1'), DocumentKind::SalesInvoice,
        $configuration, 'receivable', [new Obligation($party, Money::of('232', $usd), '2026-01-31')],
        [new TaxLine('revenue', '100', '1', 'First foreign line', ['EXAMPLE']), new TaxLine('revenue', '100', '1', 'Second foreign line', ['EXAMPLE'])], ['EXAMPLE' => 'tax.output'],
        exchangeRate: new ExchangeRate($usd, $this->kes, '1.5', '2026-01-15', 'approved-rate', Rounding::HalfEven)));
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $first = $this->ledger->taxes->register($snapshot, limit: 1);
    $second = $this->ledger->taxes->register($snapshot, limit: 1, afterSequence: (string) $first[0]->sequence, afterLine: $first[0]->line_no, afterCode: $first[0]->tax_code);
    expect($first[0]->base_minor)->toBe('15000')->and($first[0]->tax_minor)->toBe('2400')
        ->and($first[0]->line_no)->toBe(1)->and($second[0]->line_no)->toBe(2);
});

it('prepares and records tax returns after the financial period closes without reopening the ledger', function () {
    $configuration = taxRegisterConfiguration($this->ledger, $this->book);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $this->ledger->periods->close($this->book, $this->period, 'Close financial year');
    $return = $this->ledger->taxes->prepare($snapshot, 'closed-period-return', $configuration, 'vat', '2026-01-01');
    $this->ledger->taxes->finalize($this->book, $return, 'closed-period-approval', '2026-02-01', 'Review closed financial period tax');
    $this->ledger->taxes->file($this->book, $return, 'closed-period-filing', '2026-02-01', 'CLOSED-PERIOD-RECEIPT', 'Record external filing');
    expect($this->ledger->taxes->evidence($this->book, $return)['status'])->toBe('filed');
});

it('invalidates a prepared carry-forward when its preceding filed return is amended', function () {
    $configuration = taxRegisterConfiguration($this->ledger, $this->book);
    $party = $this->ledger->catalog->party($this->entity, 'carry-forward-party', 'Supplier');
    $invoice = taxRegisterDocument($this->ledger, $this->book, $configuration, $party, 'carry-forward-bill', DocumentKind::PurchaseBill);
    $this->ledger->taxes->decide($this->book, 'initial-carry-claim', $configuration, 'vat', '2026-01-31', $invoice->id, 1, 'EXAMPLE-RC', 'claim', '1', 'Original eligibility');
    $january = $this->ledger->taxes->prepare($this->ledger->reports->snapshot($this->book, '2026-01-31'), 'carry-january', $configuration, 'vat', '2026-01-01');
    $this->ledger->taxes->finalize($this->book, $january, 'approve-carry-january', '2026-02-01', 'Approve original credit');
    $this->ledger->taxes->file($this->book, $january, 'file-carry-january', '2026-02-01', 'CARRY-JANUARY', 'Original external receipt');
    $february = $this->ledger->taxes->prepare($this->ledger->reports->snapshot($this->book, '2026-02-28'), 'carry-february', $configuration, 'vat', '2026-02-01', priorPeriodId: $january);
    $this->ledger->taxes->decide($this->book, 'withdraw-carry-claim', $configuration, 'vat', '2026-01-31', $invoice->id, 1, 'EXAMPLE-RC', 'deny', '0', 'Correct original eligibility assessment');
    $amended = $this->ledger->taxes->prepare($this->ledger->reports->snapshot($this->book, '2026-01-31'), 'carry-january-amended', $configuration, 'vat', '2026-01-01', previousId: $january);
    $this->ledger->taxes->finalize($this->book, $amended, 'approve-carry-amendment', '2026-02-02', 'Review corrected credit');
    $this->ledger->taxes->file($this->book, $amended, 'file-carry-amendment', '2026-02-02', 'CARRY-JANUARY-AMENDED', 'Amended external receipt');
    expect($this->ledger->taxes->evidence($this->book, $february)['result']['opening_credit_minor'])->toBe('1600');
    $this->ledger->taxes->finalize($this->book, $february, 'reject-old-carry', '2026-03-01', 'Attempt stale credit carry-forward');
})->throws(QueryException::class, 'opening-credit return was amended');

it('does not silently omit an available filed credit from the next return', function () {
    $configuration = taxRegisterConfiguration($this->ledger, $this->book);
    $return = $this->ledger->taxes->prepare($this->ledger->reports->snapshot($this->book, '2026-01-31'), 'imported-credit-january', $configuration, 'vat', '2026-01-01',
        openingCredit: '100', openingReference: 'reviewed-opening-credit-certificate');
    $this->ledger->taxes->finalize($this->book, $return, 'approve-imported-credit', '2026-02-01', 'Approve opening credit evidence');
    $this->ledger->taxes->file($this->book, $return, 'file-imported-credit', '2026-02-01', 'IMPORTED-CREDIT', 'External receipt');
    $this->ledger->taxes->prepare($this->ledger->reports->snapshot($this->book, '2026-02-28'), 'omit-prior-credit', $configuration, 'vat', '2026-02-01');
})->throws(QueryException::class, 'Link the preceding filed tax credit');

it('retains source parties and complete claim decision history across partial claim rounding', function () {
    $configuration = taxRegisterConfiguration($this->ledger, $this->book);
    $party = $this->ledger->catalog->party($this->entity, 'small-input-party', 'Supplier');
    $invoice = taxRegisterDocument($this->ledger, $this->book, $configuration, $party, 'small-input', DocumentKind::PurchaseBill, '0.04');
    $half = $this->ledger->taxes->decide($this->book, 'half-small-input', $configuration, 'vat', '2026-01-31', $invoice->id, 1, 'EXAMPLE-RC', 'claim', '0.5', 'Partial eligibility');
    $before = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $this->ledger->taxes->decide($this->book, 'all-small-input', $configuration, 'vat', '2026-02-28', $invoice->id, 1, 'EXAMPLE-RC', 'claim', '1', 'Full eligibility');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-02-28');
    $register = $this->ledger->taxes->register($snapshot);
    expect(json_decode($register[0]->party_ids, true, 512, JSON_THROW_ON_ERROR))->toBe([$party])
        ->and($register[0]->source_reference)->toBe('small-input')
        ->and($this->ledger->taxes->decisions($before, 'vat', $invoice->id))->toHaveCount(1)
        ->and($this->ledger->taxes->decisions($snapshot, 'vat', $invoice->id))->toHaveCount(2)
        ->and($this->ledger->taxes->decisions($before, 'vat', $invoice->id)[0]->id)->toBe($half)
        ->and($this->ledger->taxes->preview($snapshot, $configuration, 'vat', '2026-02-01')['totals']['input_claimed'])->toBe('1');
});

it('rejects unknown tax reconciliation purposes instead of manufacturing an empty payable', function () {
    $configuration = taxRegisterConfiguration($this->ledger, $this->book);
    $tax = $this->ledger->configurations->account($this->book, $configuration, 'tax.output');
    $journal = $this->ledger->journals->post(new JournalRequest($this->book, 'unknown-purpose-journal', '2026-01-31', 'Tax payment', [
        new Posting($tax, Side::Debit, Money::of('1', $this->kes)), new Posting($this->cash, Side::Credit, Money::of('1', $this->kes)),
    ]));
    $this->ledger->taxes->reconcile($this->book, 'unknown-purpose', $configuration, 'vat', '2026-01-31', $journal->id, 1, 'misspelled-purpose', 'Invalid purpose');
})->throws(QueryException::class, 'approved purpose');

it('invalidates import claims after their payment is corrected and records the recapture', function () {
    $configuration = taxRegisterConfiguration($this->ledger, $this->book);
    $assessment = $this->ledger->treatments->post(taxTreatmentRequest($this->book, $configuration, 'reversed-payment-assessment', [
        'operation' => 'reverse_charge', 'tax_code' => 'EXAMPLE-RC', 'tax_base' => '1000', 'recoverable_fraction' => '1',
    ]));
    $tax = $this->ledger->configurations->account($this->book, $configuration, 'tax.reverse_charge.payable');
    $payment = $this->ledger->journals->post(new JournalRequest($this->book, 'reversed-import-payment', '2026-01-31', 'Tax payment', [
        new Posting($tax, Side::Debit, Money::of('160', $this->kes)), new Posting($this->cash, Side::Credit, Money::of('160', $this->kes)),
    ]));
    $claim = $this->ledger->taxes->decide($this->book, 'original-paid-claim', $configuration, 'vat', '2026-01-31', $assessment->id, 2, 'EXAMPLE-RC', 'claim', '1', 'Reviewed paid input', ['payment_journal_id' => $payment->id]);
    $this->ledger->journals->reverse($this->book, $payment->id, 'cancel-import-payment', '2026-02-01', 'Correct failed payment');
    $preview = $this->ledger->taxes->preview($this->ledger->reports->snapshot($this->book, '2026-02-28'), $configuration, 'vat', '2026-02-01');
    expect($preview['invalid_claims'])->toBe([$claim]);
    $this->ledger->taxes->decide($this->book, 'recapture-unpaid-input', $configuration, 'vat', '2026-02-28', $assessment->id, 2, 'EXAMPLE-RC', 'deny', '0', 'Recapture unpaid claim');
    $preview = $this->ledger->taxes->preview($this->ledger->reports->snapshot($this->book, '2026-02-28'), $configuration, 'vat', '2026-02-01');
    expect($preview['invalid_claims'])->toBe([])->and($preview['totals']['input_claimed'])->toBe('-16000');
});

it('requires tax permission independently of financial read and posting permissions', function () {
    $configuration = taxRegisterConfiguration($this->ledger, $this->book);
    $denied = (new Ledger($this->storage->connection, new class implements Authorizer
    {
        public function allows(Scope $scope, Permission $permission, ?string $bookId = null): bool
        {
            return $permission !== Permission::Tax;
        }
    }))->for($this->scope);
    $snapshot = $denied->reports->snapshot($this->book, '2026-01-31');
    expect($denied->taxes->register($snapshot))->toBe([]);
    $denied->taxes->prepare($snapshot, 'denied-tax-prepare', $configuration, 'vat', '2026-01-01');
})->throws(LedgerException::class, 'ledger.tax');

it('enforces the tax return filing and void state transitions', function (bool $filed) {
    $configuration = taxRegisterConfiguration($this->ledger, $this->book);
    $return = $this->ledger->taxes->prepare($this->ledger->reports->snapshot($this->book, '2026-01-31'), 'tax-state-machine', $configuration, 'vat', '2026-01-01');
    if ($filed) {
        $this->ledger->taxes->finalize($this->book, $return, 'approve-state-return', '2026-02-01', 'Review nil return');
        $this->ledger->taxes->file($this->book, $return, 'file-state-return', '2026-02-01', 'STATE-RETURN-RECEIPT', 'Record external filing');
    }
    try {
        if ($filed) {
            $this->ledger->taxes->void($this->book, $return, 'void-filed-return', '2026-02-02', 'Attempt to erase filed history');
        } else {
            $this->ledger->taxes->file($this->book, $return, 'file-unapproved-return', '2026-02-01', 'UNAPPROVED-RECEIPT', 'Attempt unreviewed filing');
        }
        test()->fail('Invalid tax return state transition must fail.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain($filed ? 'Filed or voided tax returns cannot be voided' : 'Filing requires a finalized return');
    }
    expect($this->ledger->taxes->evidence($this->book, $return)['status'])->toBe($filed ? 'filed' : 'prepared');
})->with([false, true]);

it('derives exact compound taxable bases from inclusive and exclusive source rules', function (bool $inclusive) {
    taxRegisterConfiguration($this->ledger, $this->book);
    $configuration = $this->ledger->configurations->install($this->book, new Pack('compound-register', '1', roles: ['receivable' => '1100', 'revenue' => '4000', 'tax.output' => '2200'],
        taxRules: [new TaxRule('FIRST', '0.1', '2026-01-01', null, 'first:1', inclusive: $inclusive), new TaxRule('SECOND', '0.05', '2026-01-01', null, 'second:1', inclusive: $inclusive, compound: true)]));
    $party = $this->ledger->catalog->party($this->entity, 'compound-register-party', 'Customer');
    $this->ledger->documents->issueTaxed(new TaxedDocumentRequest($this->book, 'compound-register-sale', '2026-01-15', new Source('tax-register', 'compound'), DocumentKind::SalesInvoice,
        $configuration, 'receivable', [new Obligation($party, Money::of('115.50', $this->kes), '2026-01-31')],
        [new TaxLine('revenue', $inclusive ? '115.50' : '100', '1', 'Ordered compound tax', ['FIRST', 'SECOND'])], ['FIRST' => 'tax.output', 'SECOND' => 'tax.output']));
    $rows = collect($this->ledger->taxes->register($this->ledger->reports->snapshot($this->book, '2026-01-31')))->keyBy('tax_code');
    expect($rows['FIRST']->base_minor)->toBe('10000')->and($rows['FIRST']->tax_minor)->toBe('1000')
        ->and($rows['SECOND']->base_minor)->toBe('11000')->and($rows['SECOND']->tax_minor)->toBe('550');
})->with([false, true]);
