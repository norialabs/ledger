<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Noria\Ledger\Access\Authorizer;
use Noria\Ledger\Access\DenyAccess;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Access\Scope;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Accounting\Source;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Ledger;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Money\Rounding;
use Noria\Ledger\Session;
use Noria\Ledger\Tests\Support\PermitAll;

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
    $ledger = new Ledger($this->connection(), new DenyAccess);
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
    $ids = fn (array $journals): array => array_column($journals, 'id');

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

function reviewer(Connection $connection, Scope $scope, ?Authorizer $authorizer = null): Session
{
    return (new Ledger($connection, $authorizer ?? new PermitAll))->for(new Scope($scope->tenantId, 'reviewer-1', $scope->firmId));
}

it('posts a prepared draft only when another person approves it', function () {
    $usd = new Currency('USD', 2);
    $usdBank = $this->ledger->catalog->account($this->book, '1010', 'USD bank', AccountType::Asset, $usd, cashFlow: 'cash');
    $request = new JournalRequest($this->book, 'draft-1', '2026-01-10', 'USD deposit', [
        new Posting($usdBank, Side::Debit, Money::of('10', $usd), new ExchangeRate($usd, $this->kes, '130', '2026-01-10', 'treasury', Rounding::HalfEven), dimensions: []),
        new Posting($this->income, Side::Credit, Money::of('1300', $this->kes)),
    ], ['channel' => 'manual'], source: new Source('manual', 'MJ-1'));
    $draft = $this->ledger->drafts->prepare($request);
    expect($draft->status)->toBe('pending')
        ->and($this->ledger->drafts->prepare($request)->id)->toBe($draft->id)
        ->and($draft->preparedBy)->toBe('accountant-1')
        ->and($this->ledger->journals->search($this->book))->toBe([])
        ->and(fn () => $this->ledger->drafts->approve($this->book, $draft->id))->toThrow(QueryException::class, 'other than its preparer');

    $journal = reviewer($this->connection(), $this->scope)->drafts->approve($this->book, $draft->id, 'Checked to bank advice');
    $approved = $this->ledger->drafts->get($this->book, $draft->id);
    expect($approved->status)->toBe('approved')
        ->and($approved->decidedBy)->toBe('reviewer-1')
        ->and($approved->journalId)->toBe($journal->id)
        ->and($journal->source?->reference)->toBe('MJ-1')
        ->and($journal->actorId)->toBe('reviewer-1')
        ->and($this->ledger->journals->lines($this->book, $journal->id)[0]->rateSource)->toBe('treasury')
        ->and(reviewer($this->connection(), $this->scope)->drafts->approve($this->book, $draft->id)->id)->toBe($journal->id)
        ->and($this->ledger->drafts->list($this->book))->toBe([]);
});

it('revises, rejects and withdraws drafts without posting them', function () {
    $amount = Money::of('10', $this->kes);
    $first = $this->ledger->drafts->prepare(new JournalRequest($this->book, 'draft-a', '2026-01-10', 'Receipt', [new Posting($this->cash, Side::Debit, $amount), new Posting($this->income, Side::Credit, $amount)]));
    $revised = $this->ledger->drafts->prepare(new JournalRequest($this->book, 'draft-a2', '2026-01-10', 'Receipt corrected', [new Posting($this->cash, Side::Debit, $amount), new Posting($this->income, Side::Credit, $amount)]), $first->id);
    expect($this->ledger->drafts->get($this->book, $first->id)->status)->toBe('superseded')
        ->and(fn () => reviewer($this->connection(), $this->scope)->drafts->approve($this->book, $first->id))->toThrow(LedgerException::class, 'Only a pending draft');

    $rejected = reviewer($this->connection(), $this->scope)->drafts->reject($this->book, $revised->id, 'Wrong income account');
    expect($rejected->status)->toBe('rejected')->and($rejected->reason)->toBe('Wrong income account');

    $other = $this->ledger->drafts->prepare(new JournalRequest($this->book, 'draft-b', '2026-01-11', 'Duplicate', [new Posting($this->cash, Side::Debit, $amount), new Posting($this->income, Side::Credit, $amount)]));
    expect(fn () => reviewer($this->connection(), $this->scope)->drafts->withdraw($this->book, $other->id, 'Not mine'))->toThrow(QueryException::class, 'Only the preparer');
    expect($this->ledger->drafts->withdraw($this->book, $other->id, 'Entered twice')->status)->toBe('withdrawn')
        ->and(array_map(fn ($draft) => $draft->status, $this->ledger->drafts->list($this->book, null)))->toBe(['superseded', 'rejected', 'withdrawn'])
        ->and($this->ledger->journals->search($this->book))->toBe([]);
});

it('lets approvers without posting rights post approved drafts while preparers cannot post directly', function () {
    $roles = new class implements Authorizer
    {
        public function allows(Scope $scope, Permission $permission, ?string $bookId = null): bool
        {
            return match ($scope->actorId) {
                'accountant-1' => in_array($permission, [Permission::Prepare, Permission::Read], true),
                default => in_array($permission, [Permission::Approve, Permission::Read], true),
            };
        }
    };
    $preparer = (new Ledger($this->connection(), $roles))->for($this->scope);
    $amount = Money::of('10', $this->kes);
    $request = new JournalRequest($this->book, 'limited', '2026-01-10', 'Receipt', [new Posting($this->cash, Side::Debit, $amount), new Posting($this->income, Side::Credit, $amount)]);
    $draft = $preparer->drafts->prepare($request);
    expect(fn () => $preparer->journals->post($request))->toThrow(LedgerException::class, 'access denied')
        ->and(reviewer($this->connection(), $this->scope, $roles)->drafts->approve($this->book, $draft->id)->sequence)->not->toBeEmpty()
        ->and(fn () => reviewer($this->connection(), $this->scope, $roles)->journals->post(new JournalRequest($this->book, 'direct', '2026-01-10', 'Receipt', [new Posting($this->cash, Side::Debit, $amount), new Posting($this->income, Side::Credit, $amount)])))->toThrow(LedgerException::class, 'access denied');
});

it('reserves draft journal keys for recorded approvals', function () {
    $amount = Money::of('10', $this->kes);
    $request = new JournalRequest($this->book, 'draft:'.Str::uuid7(), '2026-01-10', 'Receipt', [new Posting($this->cash, Side::Debit, $amount), new Posting($this->income, Side::Credit, $amount)]);
    expect(fn () => $this->ledger->journals->post($request))->toThrow(LedgerException::class, 'reserved for approved drafts');
    $this->storage->run(Permission::Read, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE'), $this->book);
    $this->ledger->journals->postApprovedDraft($request);
})->throws(QueryException::class, 'recorded approval');
