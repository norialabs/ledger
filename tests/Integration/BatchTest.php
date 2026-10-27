<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use NoriaLabs\Ledger\Accounting\AccountType;
use NoriaLabs\Ledger\Accounting\JournalRequest;
use NoriaLabs\Ledger\Accounting\Posting;
use NoriaLabs\Ledger\Accounting\Side;
use NoriaLabs\Ledger\Money\Money;

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
    $this->ledger->batches->reverse('intercompany-1', 'reverse-intercompany-1', '2026-02-01', 'Cancel transfer');
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
        test()->fail('The batch must roll back.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('balance exactly');
    }

    foreach ($this->ledger->reports->snapshots([$this->book, $this->otherBook], '2026-01-31') as $snapshot) {
        expect($snapshot->throughSequence)->toBe('0');
        expect($this->ledger->reports->generalLedger($snapshot))->toBe([]);
    }
});
