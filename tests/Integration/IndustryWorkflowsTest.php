<?php

declare(strict_types=1);

use NoriaLabs\Ledger\Accounting\AccountType;
use NoriaLabs\Ledger\Accounting\AllocationRequest;
use NoriaLabs\Ledger\Accounting\JournalRequest;
use NoriaLabs\Ledger\Accounting\Posting;
use NoriaLabs\Ledger\Accounting\Side;
use NoriaLabs\Ledger\Accounting\Source;
use NoriaLabs\Ledger\Documents\Charge;
use NoriaLabs\Ledger\Documents\DocumentKind;
use NoriaLabs\Ledger\Documents\DocumentRequest;
use NoriaLabs\Ledger\Documents\Obligation;
use NoriaLabs\Ledger\Documents\PaymentRequest;
use NoriaLabs\Ledger\Money\Money;

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
