<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Banking\StatementLine;
use Noria\Ledger\Banking\StatementRequest;
use Noria\Ledger\Money\Money;

it('matches bank evidence without posting cash twice and preserves earlier snapshots', function () {
    $money = Money::of('100', $this->kes);
    $journal = $this->ledger->journals->post(new JournalRequest($this->book, 'bank-receipt', '2026-01-01', 'Receipt', [new Posting($this->cash, Side::Debit, $money), new Posting($this->income, Side::Credit, $money)]));
    $request = new StatementRequest($this->book, $this->cash, 'JAN-2026', '2026-01-01', '2026-01-31', Money::of('0', $this->kes), $money,
        [new StatementLine('BANK-TXN-1', '2026-01-01', $money, 'Receipt')]);
    $id = $this->ledger->banking->import($request);
    expect($this->ledger->banking->import($request))->toBe($id);
    $before = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $line = $this->ledger->banking->lines($this->book, $id)[0];
    $match = $this->ledger->banking->match($this->book, 'match-1', $line->id, $journal->id, 1, $money, '2026-01-31', 'Confirmed bank receipt');
    expect($this->ledger->banking->match($this->book, 'match-1', $line->id, $journal->id, 1, $money, '2026-01-31', 'Confirmed bank receipt'))->toBe($match);
    $after = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $reconciliation = $this->ledger->banking->reconciliation($after, $id);
    expect($reconciliation->ledgerBalance->decimal())->toBe('100.00');
    expect($reconciliation->statementBalance->decimal())->toBe('100.00');
    expect($reconciliation->unmatchedLedger->decimal())->toBe('0.00');
    expect($reconciliation->unmatchedStatement->decimal())->toBe('0.00');
    expect($reconciliation->unexplained->decimal())->toBe('0.00');
    expect($this->ledger->banking->reconciliation($before, $id)->unmatchedLedger->decimal())->toBe('100.00');
    $this->ledger->banking->release($this->book, $match, 'release-match', '2026-02-01', 'Wrong match');
    expect($this->ledger->banking->reconciliation($after, $id)->unmatchedLedger->decimal())->toBe('0.00');
});

it('rejects bank matching beyond a movement amount', function () {
    $money = Money::of('100', $this->kes);
    $journal = $this->ledger->journals->post(new JournalRequest($this->book, 'receipt', '2026-01-01', 'Receipt', [new Posting($this->cash, Side::Debit, $money), new Posting($this->income, Side::Credit, $money)]));
    $statement = $this->ledger->banking->import(new StatementRequest($this->book, $this->cash, 'JAN', '2026-01-01', '2026-01-31', Money::of('0', $this->kes), $money, [new StatementLine('TXN', '2026-01-01', $money, 'Receipt')]));
    $line = $this->ledger->banking->lines($this->book, $statement)[0];
    $this->ledger->banking->match($this->book, 'match', $line->id, $journal->id, 1, Money::of('101', $this->kes), '2026-01-31', 'Invalid');
})->throws(QueryException::class, 'Match exceeds');

it('retains voided bank evidence and accepts a corrected import', function () {
    $money = Money::of('100', $this->kes);
    $line = new StatementLine('TXN-1', '2026-01-01', $money, 'Receipt');
    $statement = $this->ledger->banking->import(new StatementRequest($this->book, $this->cash, 'ORIGINAL', '2026-01-01', '2026-01-31', Money::of('0', $this->kes), $money, [$line]));
    $old = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $this->ledger->banking->voidStatement($this->book, $statement, '2026-02-01', 'Opening balance was omitted');
    $replacement = $this->ledger->banking->import(new StatementRequest($this->book, $this->cash, 'CORRECTED', '2026-01-01', '2026-01-31', Money::of('10', $this->kes), Money::of('110', $this->kes), [$line]));
    expect($this->ledger->banking->reconciliation($old, $statement)->statementBalance->decimal())->toBe('100.00');
    $current = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    expect($this->ledger->banking->reconciliation($current, $replacement)->unexplained->decimal())->toBe('-10.00');
});

it('requires statement void dates to follow the closing date and all match corrections', function (bool $matched) {
    $amount = Money::of('100', $this->kes);
    $journal = $this->ledger->journals->post(new JournalRequest($this->book, 'receipt', '2026-01-01', 'Receipt', [new Posting($this->cash, Side::Debit, $amount), new Posting($this->income, Side::Credit, $amount)]));
    $statement = $this->ledger->banking->import(new StatementRequest($this->book, $this->cash, 'ORIGINAL', '2026-01-01', '2026-01-31', Money::of('0', $this->kes), $amount, [new StatementLine('TXN', '2026-01-01', $amount, 'Receipt')]));
    if ($matched) {
        $line = $this->ledger->banking->lines($this->book, $statement)[0];
        $match = $this->ledger->banking->match($this->book, 'match', $line->id, $journal->id, 1, $amount, '2026-01-31', 'Match');
        $this->ledger->banking->release($this->book, $match, 'release', '2026-02-02', 'Release');
    }
    $this->ledger->banking->voidStatement($this->book, $statement, $matched ? '2026-02-01' : '2026-01-30', 'Invalid historical correction');
})->with([false, true])->throws(QueryException::class, 'closing date and match history');
