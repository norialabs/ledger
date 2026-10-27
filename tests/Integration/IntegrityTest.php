<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use NoriaLabs\Ledger\Access\Authorizer;
use NoriaLabs\Ledger\Access\Permission;
use NoriaLabs\Ledger\Access\Scope;
use NoriaLabs\Ledger\Accounting\AccountType;
use NoriaLabs\Ledger\Accounting\JournalRequest;
use NoriaLabs\Ledger\Accounting\Posting;
use NoriaLabs\Ledger\Accounting\PostingPolicy;
use NoriaLabs\Ledger\Accounting\Side;
use NoriaLabs\Ledger\Accounting\Source;
use NoriaLabs\Ledger\Exceptions\LedgerException;
use NoriaLabs\Ledger\Ledger;
use NoriaLabs\Ledger\Money\Money;
use NoriaLabs\Ledger\Tests\Support\PermitAll;

it('uses a database role subject to row-level security and restores scope after each operation', function () {
    $db = $this->app['db']->connection();
    $role = $db->selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
    expect($role->rolsuper)->toBeFalse();
    expect($role->rolbypassrls)->toBeFalse();
    expect($db->table('ledger_books')->count())->toBe(0);
    $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect($db->table('ledger_books')->count())->toBe(0);
    $this->storage->run(Permission::Read, function () use ($db): void {
        expect($db->table('ledger_books')->count())->toBe(1);
    }, $this->book);
    expect($db->table('ledger_books')->count())->toBe(0);
});

it('cannot read a different tenant even when the book identifier is known', function () {
    $other = $this->app->make(Ledger::class)->for(new Scope((string) Str::uuid(), 'other-client'));
    $other->reports->snapshot($this->book, '2026-12-31');
})->throws(LedgerException::class, 'not found');

it('passes the target book to the host authorizer for company-specific access', function () {
    $allowed = $this->book;
    $authorizer = new class($allowed) implements Authorizer
    {
        public function __construct(private string $allowed) {}

        public function allows(Scope $scope, Permission $permission, ?string $bookId = null): bool
        {
            return $permission === Permission::Read && $bookId === $this->allowed;
        }
    };
    $otherBook = $this->ledger->catalog->book($this->entity, 'Restricted management book', $this->kes);
    $restricted = (new Ledger($this->app['db']->connection(), $authorizer))->for($this->scope);
    expect($restricted->reports->snapshot($this->book, '2026-12-31')->bookId)->toBe($this->book);
    $restricted->reports->snapshot($otherBook, '2026-12-31');
})->throws(LedgerException::class, 'access denied');

it('rejects direct editing, deletion, and truncation of posted history', function (string $operation) {
    $money = Money::of('100', $this->kes);
    $journal = $this->ledger->journals->post(new JournalRequest($this->book, 'immutable', '2026-01-01', 'Receipt', [new Posting($this->cash, Side::Debit, $money), new Posting($this->income, Side::Credit, $money)]));
    $this->storage->run(Permission::Post, function () use ($operation, $journal): void {
        $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE');
        $query = $this->storage->table('ledger_journals')->where('id', $journal->id);

        match ($operation) {
            'update' => $query->update(['description' => 'Rewrite history']),
            'delete' => $query->delete(),
            'truncate' => $this->storage->connection->statement('TRUNCATE ledger_journals CASCADE'),
        };
    }, $this->book);
})->with(['update', 'delete', 'truncate'])->throws(QueryException::class, 'append-only');

it('rejects insertion of a posting independently of its journal', function () {
    $money = Money::of('100', $this->kes);
    $journal = $this->ledger->journals->post(new JournalRequest($this->book, 'original', '2026-01-01', 'Receipt', [new Posting($this->cash, Side::Debit, $money), new Posting($this->income, Side::Credit, $money)]));
    $this->storage->run(Permission::Post, function () use ($journal): void {
        $line = (array) $this->storage->table('ledger_postings')->where('journal_id', $journal->id)->first();
        $line['line_no'] = 3;
        $this->storage->connection->table('ledger_postings')->insert($line);
    }, $this->book);
})->throws(QueryException::class, 'only be derived');

it('independently verifies functional amounts supplied by direct SQL', function () {
    $money = Money::of('100', $this->kes);
    $journal = $this->ledger->journals->post(new JournalRequest($this->book, 'original', '2026-01-01', 'Receipt', [new Posting($this->cash, Side::Debit, $money), new Posting($this->income, Side::Credit, $money)]));
    $this->storage->run(Permission::Post, function () use ($journal): void {
        $row = (array) $this->storage->table('ledger_journals')->where('id', $journal->id)->first();
        $lines = json_decode($row['postings'], false, 512, JSON_THROW_ON_ERROR);

        foreach ($lines as $line) {
            $line->functional_minor = '10001';
        }

        $row['id'] = (string) Str::uuid();
        $row['idempotency_key'] = 'tampered';
        $row['postings'] = json_encode($lines, JSON_THROW_ON_ERROR);
        $this->storage->connection->table('ledger_journals')->insert($row);
    }, $this->book);
})->throws(QueryException::class, 'does not match the exchange rate');

it('rolls back derived postings and sequence when a later line fails', function () {
    $group = $this->ledger->catalog->account($this->book, '4999', 'Income group', AccountType::Income, group: true);
    $money = Money::of('100', $this->kes);

    try {
        $this->ledger->journals->post(new JournalRequest($this->book, 'invalid', '2026-01-01', 'Invalid group posting', [new Posting($this->cash, Side::Debit, $money), new Posting($group, Side::Credit, $money)]));
        test()->fail('Posting to a group must fail.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('a group');
    }

    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect($snapshot->throughSequence)->toBe('0');
    expect($this->ledger->reports->generalLedger($snapshot))->toBe([]);
    $journal = $this->ledger->journals->post(new JournalRequest($this->book, 'valid', '2026-01-01', 'Receipt', [new Posting($this->cash, Side::Debit, $money), new Posting($this->income, Side::Credit, $money)]));
    expect($journal->sequence)->toBe('1');
});

it('rejects posting a party from another legal entity', function () {
    $otherEntity = $this->ledger->catalog->entity('Other company');
    $party = $this->ledger->catalog->party($otherEntity, 'other-customer', 'Other customer');
    $ar = $this->ledger->catalog->account($this->book, '1100', 'Receivables', AccountType::Asset, control: true);
    $money = Money::of('100', $this->kes);
    $this->ledger->journals->post(new JournalRequest($this->book, 'cross-company', '2026-01-01', 'Invalid', [new Posting($ar, Side::Debit, $money, partyId: $party), new Posting($this->income, Side::Credit, $money)]));
})->throws(QueryException::class, 'different entity');

it('rejects overlapping accounting periods', function () {
    $this->ledger->periods->open($this->book, 'Overlap', '2026-12-31', '2027-01-31');
})->throws(QueryException::class, 'cannot overlap');

it('preserves source uniqueness separately from transport retry keys', function () {
    $money = Money::of('100', $this->kes);

    foreach (['attempt-1', 'attempt-2'] as $key) {
        $this->ledger->journals->post(new JournalRequest($this->book, $key, '2026-01-01', 'Receipt', [new Posting($this->cash, Side::Debit, $money), new Posting($this->income, Side::Credit, $money)], source: new Source('bank.receipt', 'external-1')));
    }
})->throws(QueryException::class, 'unique');

it('lets host policies veto a journal before anything is posted', function () {
    $policy = new class implements PostingPolicy
    {
        public function version(): string
        {
            return 'independent-review:v1';
        }

        public function validate(Scope $scope, JournalRequest $request): void
        {
            throw new LedgerException('Independent review is required.');
        }
    };
    $ledger = (new Ledger($this->app['db']->connection(), new PermitAll, [$policy]))->for($this->scope);
    $money = Money::of('100', $this->kes);

    try {
        $ledger->journals->post(new JournalRequest($this->book, 'review-required', '2026-01-01', 'Receipt', [new Posting($this->cash, Side::Debit, $money), new Posting($this->income, Side::Credit, $money)]));
        test()->fail('Policy must veto the posting.');
    } catch (LedgerException $exception) {
        expect($exception->getMessage())->toBe('Independent review is required.');
    }

    expect($this->ledger->reports->snapshot($this->book, '2026-12-31')->throughSequence)->toBe('0');
});
