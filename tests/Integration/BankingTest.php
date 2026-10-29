<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Banking\StatementLine;
use Noria\Ledger\Banking\StatementRequest;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Reporting\ReportingMovement;

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
    $release = $this->ledger->banking->release($this->book, $match, 'release-match', '2026-02-01', 'Wrong match');
    expect($this->ledger->banking->release($this->book, $match, 'release-match', '2026-02-01', 'Wrong match'))->toBe($release);
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
    $void = $this->ledger->banking->voidStatement($this->book, $statement, '2026-02-01', 'Opening balance was omitted');
    expect($this->ledger->banking->voidStatement($this->book, $statement, '2026-02-01', 'Opening balance was omitted'))->toBe($void)
        ->and(fn () => $this->ledger->banking->voidStatement($this->book, $statement, '2026-02-02', 'Opening balance was omitted'))->toThrow(LedgerException::class, 'another reason or date');
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

it('suggests exact-amount matches, auto-matches unambiguous lines and posts unmatched lines to a counter account', function () {
    $post = fn (string $key, string $date, string $amount, string $description) => $this->ledger->journals->post(new JournalRequest($this->book, $key, $date, $description, [
        new Posting($this->cash, Side::Debit, Money::of($amount, $this->kes)), new Posting($this->income, Side::Credit, Money::of($amount, $this->kes)),
    ]))->id;
    $referenced = $post('r1', '2026-01-05', '1000', 'Water bill payment QAB1001');
    $twinA = $post('r2', '2026-01-10', '300', 'Payment');
    $post('r3', '2026-01-10', '300', 'Payment');
    $charges = $this->ledger->catalog->account($this->book, '5500', 'Bank charges', AccountType::Expense);
    $statement = $this->ledger->banking->import(new StatementRequest($this->book, $this->cash, 'JAN', '2026-01-01', '2026-01-31', Money::of('0', $this->kes), Money::of('1270', $this->kes), [
        new StatementLine('QAB1001', '2026-01-06', Money::of('1000', $this->kes), 'Pay Bill QAB1001'),
        new StatementLine('QAB1002', '2026-01-10', Money::of('300', $this->kes), 'Pay Bill'),
        new StatementLine('CHG-1', '2026-01-31', Money::of('-30', $this->kes), 'Monthly charges'),
    ]));
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $suggestions = [];
    foreach ($this->ledger->banking->suggestMatches($snapshot, $statement) as $suggestion) {
        $suggestions[$suggestion['external_id']][] = $suggestion;
    }

    expect($suggestions['QAB1001'][0]['journal_id'])->toBe($referenced)
        ->and($suggestions['QAB1001'][0]['score'])->toBe(97)
        ->and($suggestions['QAB1002'])->toHaveCount(2)
        ->and($suggestions)->not->toHaveKey('CHG-1');

    $matched = $this->ledger->banking->autoMatch($snapshot, $statement, '2026-01-31');
    expect($matched)->toHaveCount(1);
    $journal = $this->ledger->banking->postLine($this->book, $this->ledger->banking->lines($this->book, $statement)[2]->id, $charges, 'Bank charges from statement', cashFlow: 'operating');
    $this->ledger->banking->match($this->book, 'manual-twin', $this->ledger->banking->lines($this->book, $statement)[1]->id, $twinA, 1, Money::of('300', $this->kes), '2026-01-31', 'Chosen by reviewer');
    $reconciliation = $this->ledger->banking->reconciliation($this->ledger->reports->snapshot($this->book, '2026-01-31'), $statement);
    expect($reconciliation->unmatchedStatement->decimal())->toBe('0.00')
        ->and($this->ledger->journals->lines($this->book, $journal)[1]->accountId)->toBe($charges)
        ->and($this->ledger->banking->postLine($this->book, $this->ledger->banking->lines($this->book, $statement)[2]->id, $charges, 'Bank charges from statement'))->toBe($journal);
});

it('attributes a bank line posted to equity so classified reports can use it', function () {
    $capital = $this->ledger->catalog->account($this->book, '3200', 'Share capital', AccountType::Equity);
    $statement = $this->ledger->banking->import(new StatementRequest($this->book, $this->cash, 'FEB', '2026-02-01', '2026-02-28', Money::of('0', $this->kes), Money::of('500', $this->kes), [
        new StatementLine('CAP-1', '2026-02-03', Money::of('500', $this->kes), 'Share subscription'),
    ]));
    $contribution = new ReportingMovement('contribution', 'board-resolution:2026-02', 'Share issue CAP-1');
    $journal = $this->ledger->banking->postLine($this->book, $this->ledger->banking->lines($this->book, $statement)[0]->id, $capital, 'Share subscription', cashFlow: 'financing', reportingMovement: $contribution);
    expect(array_map(fn ($line) => $line->reportingMovement, $this->ledger->journals->lines($this->book, $journal)))->toEqual([null, $contribution]);
});
