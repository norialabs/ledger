<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Money\Money;

beforeEach(function () {
    $otherEntity = $this->ledger->catalog->entity('Subsidiary');
    $this->otherBook = $this->ledger->catalog->book($otherEntity, 'Statutory', $this->kes);
    $this->ledger->periods->open($this->otherBook, '2026', '2026-01-01', '2026-12-31');
    $this->dueFrom = $this->ledger->catalog->account($this->book, '1200', 'Due from subsidiary', AccountType::Asset);
    $this->otherCash = $this->ledger->catalog->account($this->otherBook, '1000', 'Bank', AccountType::Asset);
    $this->dueTo = $this->ledger->catalog->account($this->otherBook, '2000', 'Due to parent', AccountType::Liability);
});

it('posts and reverses both sides of an intercompany transfer atomically', function () {
    $money = Money::of('100', $this->kes);
    $requests = [
        new JournalRequest($this->book, 'parent-transfer', '2026-01-01', 'Transfer to subsidiary', [new Posting($this->dueFrom, Side::Debit, $money), new Posting($this->cash, Side::Credit, $money)]),
        new JournalRequest($this->otherBook, 'subsidiary-transfer', '2026-01-01', 'Transfer from parent', [new Posting($this->otherCash, Side::Debit, $money), new Posting($this->dueTo, Side::Credit, $money)]),
    ];
    $first = $this->ledger->batches->post('intercompany-1', $requests, 'Treasury transfer');
    expect(array_column($this->ledger->batches->post('intercompany-1', $requests, 'Treasury transfer'), 'id'))->toBe(array_column($first, 'id'));
    $snapshots = $this->ledger->reports->snapshots([$this->book, $this->otherBook], '2026-01-31');
    expect($this->ledger->reports->generalLedger($snapshots[$this->book]))->toHaveCount(2);
    expect($this->ledger->reports->generalLedger($snapshots[$this->otherBook]))->toHaveCount(2);
    $reversals = $this->ledger->batches->reverse('intercompany-1', 'reverse-intercompany-1', '2026-02-01', 'Cancel transfer');
    expect(array_map(fn ($journal) => $journal->id, $this->ledger->batches->reverse('intercompany-1', 'reverse-intercompany-1', '2026-02-01', 'Cancel transfer')))
        ->toBe(array_map(fn ($journal) => $journal->id, $reversals));
    $snapshots = $this->ledger->reports->snapshots([$this->book, $this->otherBook], '2026-02-28');

    foreach ($snapshots as $snapshot) {
        foreach ($this->ledger->reports->trialBalance($snapshot) as $balance) {
            expect($balance->closing->decimal())->toBe('0.00');
        }
    }
});

it('rolls back the first company when posting the second company fails', function () {
    $money = Money::of('100', $this->kes);

    try {
        $this->ledger->batches->post('invalid-batch', [
            new JournalRequest($this->book, 'first', '2026-01-01', 'First', [new Posting($this->dueFrom, Side::Debit, $money), new Posting($this->cash, Side::Credit, $money)]),
            new JournalRequest($this->otherBook, 'second', '2026-01-01', 'Second', [new Posting($this->otherCash, Side::Debit, $money), new Posting($this->dueTo, Side::Credit, Money::of('99', $this->kes))]),
        ], 'Invalid transfer');
        $this->fail('The batch must roll back.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('balance exactly');
    }

    foreach ($this->ledger->reports->snapshots([$this->book, $this->otherBook], '2026-01-31') as $snapshot) {
        expect($snapshot->throughSequence)->toBe('0');
        expect($this->ledger->reports->generalLedger($snapshot))->toBe([]);
    }
});

it('rejects one-sided batch journal corrections at the deferred database boundary', function () {
    $amount = Money::of('100', $this->kes);
    $journals = $this->ledger->batches->post('linked-batch', [
        new JournalRequest($this->book, 'parent', '2026-01-01', 'Parent transfer', [new Posting($this->dueFrom, Side::Debit, $amount), new Posting($this->cash, Side::Credit, $amount)]),
        new JournalRequest($this->otherBook, 'subsidiary', '2026-01-01', 'Subsidiary transfer', [new Posting($this->otherCash, Side::Debit, $amount), new Posting($this->dueTo, Side::Credit, $amount)]),
    ], 'Linked transfer');
    $before = $this->ledger->events->cutoff($this->book);
    try {
        $this->storage->run(Permission::Reverse, function () use ($journals): void {
            $this->ledger->journals->reverse($this->book, $journals[0]->id, 'one-sided', '2026-01-02', 'Invalid one-sided correction');
            $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE');
        }, $this->book);
        $this->fail('Batch corrections must reverse every book atomically.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('matching atomic batch reversal');
    }
    expect($this->ledger->events->cutoff($this->book))->toBe($before);
    $this->ledger->batches->reverse('linked-batch', 'complete-reversal', '2026-01-02', 'Correct all books');
    $this->storage->run(Permission::Read, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE'));
});
