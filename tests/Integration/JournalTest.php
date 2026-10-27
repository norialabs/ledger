<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Noria\Ledger\Access\DenyAccess;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Ledger;
use Noria\Ledger\Money\Money;

it('posts a balanced journal atomically and replays it once', function () {
    $amount = Money::of('125.25', $this->kes);
    $request = new JournalRequest($this->book, 'receipt-1', '2026-01-01', 'Receipt', [
        new Posting($this->cash, Side::Debit, $amount), new Posting($this->income, Side::Credit, $amount),
    ]);
    $first = $this->ledger->journals->post($request);
    expect($this->ledger->journals->post($request)->id)->toBe($first->id);
    expect($first->sequence)->toBe('1');
});

it('rejects an unbalanced journal at the database boundary', function () {
    $this->ledger->journals->post(new JournalRequest($this->book, 'unbalanced', '2026-01-01', 'Invalid', [
        new Posting($this->cash, Side::Debit, Money::of('10', $this->kes)),
        new Posting($this->income, Side::Credit, Money::of('9.99', $this->kes)),
    ]));
})->throws(QueryException::class, 'Journal must balance exactly');

it('rejects reuse of a key with different content', function () {
    foreach (['10', '20'] as $amount) {
        $this->ledger->journals->post(new JournalRequest($this->book, 'same-key', '2026-01-01', 'Receipt', [
            new Posting($this->cash, Side::Debit, Money::of($amount, $this->kes)),
            new Posting($this->income, Side::Credit, Money::of($amount, $this->kes)),
        ]));
    }
})->throws(LedgerException::class, 'Idempotency key');

it('requires open periods but permits a retry after closing', function () {
    $amount = Money::of('10', $this->kes);
    $request = new JournalRequest($this->book, 'before-close', '2026-01-01', 'Receipt', [new Posting($this->cash, Side::Debit, $amount), new Posting($this->income, Side::Credit, $amount)]);
    $journal = $this->ledger->journals->post($request);
    $this->ledger->periods->close($this->book, $this->period);
    expect($this->ledger->journals->post($request)->id)->toBe($journal->id);
    $this->ledger->journals->reverse($this->book, $journal->id, 'closed-reversal', '2026-02-01', 'Correction');
})->throws(QueryException::class, 'open accounting period');

it('retains the original and records an exact opposite journal', function () {
    $amount = Money::of('10', $this->kes);
    $original = $this->ledger->journals->post(new JournalRequest($this->book, 'original', '2026-01-01', 'Receipt', [new Posting($this->cash, Side::Debit, $amount), new Posting($this->income, Side::Credit, $amount)]));
    $reversal = $this->ledger->journals->reverse($this->book, $original->id, 'reverse', '2026-02-01', 'Incorrect source');
    expect($reversal->id)->not->toBe($original->id);
    expect($this->ledger->journals->get($this->book, $original->id)->id)->toBe($original->id);
});

it('denies all operations until the host supplies an authorizer', function () {
    $ledger = new Ledger($this->app['db']->connection(), new DenyAccess);
    $ledger->for($this->scope)->catalog->entity('Forbidden');
})->throws(LedgerException::class, 'access denied');
