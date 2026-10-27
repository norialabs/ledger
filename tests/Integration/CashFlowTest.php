<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use NoriaLabs\Ledger\Accounting\AccountType;
use NoriaLabs\Ledger\Accounting\JournalRequest;
use NoriaLabs\Ledger\Accounting\Posting;
use NoriaLabs\Ledger\Accounting\Side;
use NoriaLabs\Ledger\Money\Money;

it('classifies simple cash activity and reconciles it to cash balances', function () {
    $money = Money::of('100', $this->kes);
    $this->ledger->journals->post(new JournalRequest($this->book, 'cash-income', '2026-01-01', 'Income', [new Posting($this->cash, Side::Debit, $money), new Posting($this->income, Side::Credit, $money)]));
    $flow = $this->ledger->reports->cashFlow($this->ledger->reports->snapshot($this->book, '2026-01-31'), '2026-01-01');
    expect($flow->opening->decimal())->toBe('0.00');
    expect($flow->closing->decimal())->toBe('100.00');
    expect($flow->operating->decimal())->toBe('100.00');
    expect($flow->isFullyClassified())->toBeTrue();
});

it('flags ambiguous compound entries and accepts explicit balanced classification', function () {
    $loan = $this->ledger->catalog->account($this->book, '2100', 'Loan', AccountType::Liability, cashFlow: 'financing');
    $equipment = $this->ledger->catalog->account($this->book, '1500', 'Equipment', AccountType::Asset, cashFlow: 'investing');
    $lines = [new Posting($this->cash, Side::Debit, Money::of('10', $this->kes)), new Posting($equipment, Side::Debit, Money::of('90', $this->kes)), new Posting($loan, Side::Credit, Money::of('100', $this->kes))];
    $this->ledger->journals->post(new JournalRequest($this->book, 'unclassified', '2026-01-01', 'Partly noncash acquisition', $lines));
    $flow = $this->ledger->reports->cashFlow($this->ledger->reports->snapshot($this->book, '2026-01-31'), '2026-01-01');
    expect($flow->isFullyClassified())->toBeFalse();
    expect($flow->unclassified->decimal())->toBe('10.00');
    $journal = $this->ledger->journals->post(new JournalRequest($this->book, 'classified', '2026-02-01', 'Partly noncash acquisition', $lines, ['cash_flow' => ['financing' => '1000']]));
    $flow = $this->ledger->reports->cashFlow($this->ledger->reports->snapshot($this->book, '2026-02-28'), '2026-02-01');
    expect($flow->financing->decimal())->toBe('10.00');
    expect($flow->isFullyClassified())->toBeTrue();
    $this->ledger->journals->reverse($this->book, $journal->id, 'reverse-classified', '2026-02-02', 'Cancel acquisition');
    $flow = $this->ledger->reports->cashFlow($this->ledger->reports->snapshot($this->book, '2026-02-28'), '2026-02-01');
    expect($flow->financing->decimal())->toBe('0.00');
    expect($flow->isFullyClassified())->toBeTrue();
});

it('rejects cash-flow allocations that do not equal actual cash movement', function () {
    $money = Money::of('100', $this->kes);
    $this->ledger->journals->post(new JournalRequest($this->book, 'invalid-cash-flow', '2026-01-01', 'Income', [new Posting($this->cash, Side::Debit, $money), new Posting($this->income, Side::Credit, $money)], ['cash_flow' => ['operating' => '9999']]));
})->throws(QueryException::class, 'must equal the actual cash movement');
