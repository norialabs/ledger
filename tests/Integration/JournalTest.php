<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Noria\Ledger\Access\DenyAccess;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Accounting\Source;
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

it('returns full journal headers and their stored lines', function () {
    $party = $this->ledger->catalog->party($this->entity, 'P-1', 'Payer');
    $this->ledger->catalog->dimensionValue($this->entity, 'campus', 'east');
    $amount = Money::of('12.50', $this->kes);
    $journal = $this->ledger->journals->post(new JournalRequest($this->book, 'detailed', '2026-01-05', 'Detailed receipt', [
        new Posting($this->cash, Side::Debit, $amount, partyId: $party, dimensions: ['campus' => 'east'], description: 'Banked'),
        new Posting($this->income, Side::Credit, $amount, dimensions: ['campus' => 'east']),
    ], ['channel' => 'counter'], source: new Source('receipt', 'R-1')));

    $loaded = $this->ledger->journals->get($this->book, $journal->id);
    $lines = $this->ledger->journals->lines($this->book, $journal->id);
    expect($loaded->description)->toBe('Detailed receipt')
        ->and($loaded->source?->reference)->toBe('R-1')
        ->and($loaded->actorId)->toBe('accountant-1')
        ->and($loaded->firmId)->toBe('practice-1')
        ->and($loaded->metadata)->toBe(['channel' => 'counter'])
        ->and($lines)->toHaveCount(2)
        ->and($lines[0]->side)->toBe(Side::Debit)
        ->and($lines[0]->amount->decimal())->toBe('12.50')
        ->and($lines[0]->functional->decimal())->toBe('12.50')
        ->and($lines[0]->partyId)->toBe($party)
        ->and($lines[0]->dimensions)->toBe(['campus' => 'east'])
        ->and($lines[0]->description)->toBe('Banked')
        ->and($lines[1]->lineNo)->toBe(2)
        ->and($this->ledger->journals->findBySource($this->book, new Source('receipt', 'R-1'))?->id)->toBe($journal->id)
        ->and($this->ledger->journals->findBySource($this->book, new Source('receipt', 'R-2')))->toBeNull();
});

it('searches journals by date, account, party, source, text and reversal state with a sequence cursor', function () {
    $party = $this->ledger->catalog->party($this->entity, 'P-1', 'Payer');
    $expense = $this->ledger->catalog->account($this->book, '5000', 'Expense', AccountType::Expense);
    $post = function (string $key, string $date, string $counter, ?string $partyId = null, ?Source $source = null): string {
        $amount = Money::of('10', $this->kes);

        return $this->ledger->journals->post(new JournalRequest($this->book, $key, $date, 'Journal '.$key, [
            new Posting($this->cash, Side::Debit, $amount, partyId: $partyId), new Posting($counter, Side::Credit, $amount),
        ], source: $source))->id;
    };
    $first = $post('a', '2026-01-01', $this->income, source: new Source('invoice', 'INV-7'));
    $second = $post('b', '2026-02-01', $expense, $party);
    $third = $post('c', '2026-03-01', $this->income);
    $reversal = $this->ledger->journals->reverse($this->book, $third, 'c-reversal', '2026-03-02', 'Mistake');
    $ids = fn (array $journals): array => array_map(fn ($journal) => $journal->id, $journals);

    expect($ids($this->ledger->journals->search($this->book)))->toBe([$first, $second, $third, $reversal->id])
        ->and($ids($this->ledger->journals->search($this->book, from: '2026-02-01', to: '2026-03-01')))->toBe([$second, $third])
        ->and($ids($this->ledger->journals->search($this->book, accountId: $expense)))->toBe([$second])
        ->and($ids($this->ledger->journals->search($this->book, partyId: $party)))->toBe([$second])
        ->and($ids($this->ledger->journals->search($this->book, sourceType: 'invoice')))->toBe([$first])
        ->and($ids($this->ledger->journals->search($this->book, text: 'INV-7')))->toBe([$first])
        ->and($ids($this->ledger->journals->search($this->book, reversals: true)))->toBe([$reversal->id])
        ->and($ids($this->ledger->journals->search($this->book, reversals: false, limit: 2)))->toBe([$first, $second])
        ->and($ids($this->ledger->journals->search($this->book, limit: 2, afterSequence: '2')))->toBe([$third, $reversal->id])
        ->and($ids($this->ledger->journals->search($this->book, limit: 1, afterSequence: '4', newestFirst: true)))->toBe([$third])
        ->and($this->ledger->journals->reversalOf($this->book, $third)?->id)->toBe($reversal->id)
        ->and($this->ledger->journals->reversalOf($this->book, $first))->toBeNull()
        ->and($this->ledger->journals->get($this->book, $reversal->id)->reversesId)->toBe($third);
});
