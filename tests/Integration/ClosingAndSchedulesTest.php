<?php

declare(strict_types=1);

use NoriaLabs\Ledger\Accounting\AccountType;
use NoriaLabs\Ledger\Accounting\JournalRequest;
use NoriaLabs\Ledger\Accounting\Posting;
use NoriaLabs\Ledger\Accounting\Side;
use NoriaLabs\Ledger\Money\Money;
use NoriaLabs\Ledger\Scheduling\Schedule;

it('closes income and expense into retained earnings while preserving profit reports', function () {
    $expense = $this->ledger->catalog->account($this->book, '5000', 'Expenses', AccountType::Expense);
    $retained = $this->ledger->catalog->account($this->book, '3000', 'Retained earnings', AccountType::Equity);
    $this->ledger->journals->post(new JournalRequest($this->book, 'year-activity', '2026-01-01', 'Activity', [
        new Posting($this->cash, Side::Debit, Money::of('70', $this->kes)),
        new Posting($expense, Side::Debit, Money::of('30', $this->kes)),
        new Posting($this->income, Side::Credit, Money::of('100', $this->kes)),
    ]));
    $id = $this->ledger->yearEnd->close($this->book, 'close-2026', '2026-01-01', '2026-12-31', $retained, 'Approved annual close');
    expect($this->ledger->yearEnd->close($this->book, 'close-2026', '2026-01-01', '2026-12-31', $retained, 'Approved annual close'))->toBe($id);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    $balances = collect($this->ledger->reports->trialBalance($snapshot))->keyBy('accountId');
    expect($balances[$this->income]->closing->decimal())->toBe('0.00');
    expect($balances[$expense]->closing->decimal())->toBe('0.00');
    expect($balances[$retained]->closing->decimal())->toBe('-70.00');
    expect($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['profit']->decimal())->toBe('70.00');
    expect($this->ledger->reports->balanceSheet($snapshot)['difference']->decimal())->toBe('0.00');
});

it('posts an immutable revenue recognition schedule exactly once per period', function () {
    $deferred = $this->ledger->catalog->account($this->book, '2200', 'Deferred income', AccountType::Liability);
    $plan = Schedule::equal(Money::of('100', $this->kes), ['2026-01-31', '2026-02-28', '2026-03-31']);
    $id = $this->ledger->schedules->create($this->book, 'tuition-recognition', 'Recognize tuition', $deferred, $this->income, $plan, 'tuition:v1');
    expect($this->ledger->schedules->postDue($this->book, $id, '2026-02-28'))->toHaveCount(2);
    expect($this->ledger->schedules->postDue($this->book, $id, '2026-02-28'))->toBe([]);
    expect($this->ledger->schedules->postDue($this->book, $id, '2026-03-31'))->toHaveCount(1);
    $profit = $this->ledger->reports->incomeStatement($this->ledger->reports->snapshot($this->book, '2026-03-31'), '2026-01-01');
    expect($profit['income']->decimal())->toBe('100.00');
});
