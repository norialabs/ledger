<?php

declare(strict_types=1);

use NoriaLabs\Ledger\Accounting\JournalRequest;
use NoriaLabs\Ledger\Accounting\Posting;
use NoriaLabs\Ledger\Accounting\Side;
use NoriaLabs\Ledger\Money\Money;

it('reproduces reports after later backdated entries', function () {
    $post = function (string $key, string $amount): void {
        $money = Money::of($amount, $this->kes);
        $this->ledger->journals->post(new JournalRequest($this->book, $key, '2026-01-01', 'Income', [new Posting($this->cash, Side::Debit, $money), new Posting($this->income, Side::Credit, $money)]));
    };
    $post('first', '100');
    $frozen = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $post('backdated', '50');
    expect($this->ledger->reports->incomeStatement($frozen, '2026-01-01')['profit']->decimal())->toBe('100.00');
    $latest = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    expect($this->ledger->reports->incomeStatement($latest, '2026-01-01')['profit']->decimal())->toBe('150.00');
    $sheet = $this->ledger->reports->balanceSheet($latest);
    expect($sheet['assets']->decimal())->toBe('150.00');
    expect($sheet['unclosed_earnings']->decimal())->toBe('150.00');
    expect($sheet['difference']->decimal())->toBe('0.00');
});

it('separates opening balances from reporting-period movement', function () {
    foreach (['2026-01-01' => '100', '2026-02-01' => '50'] as $date => $amount) {
        $money = Money::of($amount, $this->kes);
        $this->ledger->journals->post(new JournalRequest($this->book, $date, $date, 'Income', [new Posting($this->cash, Side::Debit, $money), new Posting($this->income, Side::Credit, $money)]));
    }
    $balances = collect($this->ledger->reports->trialBalance($this->ledger->reports->snapshot($this->book, '2026-02-28'), '2026-02-01'))->keyBy('accountId');
    expect($balances[$this->cash]->opening->decimal())->toBe('100.00');
    expect($balances[$this->cash]->debits->decimal())->toBe('50.00');
    expect($balances[$this->cash]->closing->decimal())->toBe('150.00');
});

it('filters dimensions and paginates a fixed general-ledger snapshot', function () {
    $this->ledger->catalog->dimensionValue($this->entity, 'campus', 'west');
    $this->ledger->catalog->requireDimension($this->book, $this->income, 'campus');
    $money = Money::of('100', $this->kes);
    $this->ledger->journals->post(new JournalRequest($this->book, 'dimensioned', '2026-01-01', 'Fees', [
        new Posting($this->cash, Side::Debit, $money, dimensions: ['campus' => 'west']),
        new Posting($this->income, Side::Credit, $money, dimensions: ['campus' => 'west']),
    ]));
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $first = $this->ledger->reports->generalLedger($snapshot, limit: 1);
    $second = $this->ledger->reports->generalLedger($snapshot, limit: 1, afterSequence: (string) $first[0]->sequence, afterLine: $first[0]->line_no);
    expect($first[0]->line_no)->toBe(1);
    expect($second[0]->line_no)->toBe(2);
    expect($this->ledger->reports->incomeStatement($snapshot, '2026-01-01', ['campus' => 'west'])['income']->decimal())->toBe('100.00');
});
