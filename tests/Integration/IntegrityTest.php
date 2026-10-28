<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Noria\Ledger\Access\Authorizer;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Access\Scope;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\PostingPolicy;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Accounting\Source;
use Noria\Ledger\Configuration\Pack;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Ledger;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Reporting\StatementColumn;
use Noria\Ledger\Reporting\StatementDefinition;
use Noria\Ledger\Tests\Support\PermitAll;

it('uses a database role subject to row-level security and restores scope after each operation', function () {
    $db = $this->connection();
    $role = row($db->selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user'));
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
    $other = $this->application()->make(Ledger::class)->for(new Scope((string) Str::uuid7(), 'other-client'));
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
    $restricted = (new Ledger($this->connection(), $authorizer))->for($this->scope);
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
            default => throw new UnexpectedValueException('Unknown operation '.$operation),
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
        $lines = items(json_decode(text($row['postings']), false, 512, JSON_THROW_ON_ERROR));

        foreach ($lines as $line) {
            row($line)->functional_minor = '10001';
        }

        $row['id'] = (string) Str::uuid7();
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
        $this->fail('Posting to a group must fail.');
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
    $ledger = (new Ledger($this->connection(), new PermitAll, [$policy]))->for($this->scope);
    $money = Money::of('100', $this->kes);

    try {
        $ledger->journals->post(new JournalRequest($this->book, 'review-required', '2026-01-01', 'Receipt', [new Posting($this->cash, Side::Debit, $money), new Posting($this->income, Side::Credit, $money)]));
        $this->fail('Policy must veto the posting.');
    } catch (LedgerException $exception) {
        expect($exception->getMessage())->toBe('Independent review is required.');
    }

    expect($this->ledger->reports->snapshot($this->book, '2026-12-31')->throughSequence)->toBe('0');
});

it('rechecks current book access for configuration evidence saved statements and event feeds', function (string $operation) {
    $configuration = $this->ledger->configurations->install($this->book, new Pack('access-example', 'v1'));
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $report = $this->ledger->reports->saveStatement($this->book, 'access-example', new StatementDefinition('Example', 'v1', ['cash' => ['label' => 'Cash', 'accounts' => ['1000']]]),
        [new StatementColumn('current', 'Current', $snapshot, '2026-01-01')]);
    $authorizer = new class implements Authorizer
    {
        public bool $allowed = true;

        public function allows(Scope $scope, Permission $permission, ?string $bookId = null): bool
        {
            return $this->allowed;
        }
    };
    $session = (new Ledger($this->connection(), $authorizer))->for($this->scope);
    $read = fn () => match ($operation) {
        'configuration' => $session->configurations->get($this->book, $configuration),
        'statement' => $session->reports->statementEvidence($this->book, $report),
        'events' => $session->events->page($this->book),
        default => throw new UnexpectedValueException('Unknown operation '.$operation),
    };
    expect($read())->not->toBeEmpty();
    $authorizer->allowed = false;
    $read();
})->with(['configuration', 'statement', 'events'])->throws(LedgerException::class, 'access denied');

it('rejects direct alteration and fabrication of the durable event stream', function (string $operation, string $message) {
    try {
        $this->storage->run(Permission::Configure, function () use ($operation): void {
            $query = $this->storage->table('ledger_events')->where('book_id', $this->book);
            match ($operation) {
                'update' => $query->update(['audit_id' => '0']),
                'delete' => $query->delete(),
                'truncate' => $this->storage->connection->statement('TRUNCATE ledger_events'),
                'insert' => $this->storage->connection->table('ledger_events')->insert(get_object_vars(row($query->first()))),
                default => throw new UnexpectedValueException('Unknown operation '.$operation),
            };
        }, $this->book);
        $this->fail('The event stream must reject direct mutation.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain($message);
    }
})->with([
    ['update', 'append-only'], ['delete', 'append-only'], ['truncate', 'append-only'], ['insert', 'only be derived'],
]);

it('retains financial history and exact reversals after catalog references are soft deleted', function () {
    $party = $this->ledger->catalog->party($this->entity, 'retired-party', 'Counterparty');
    $receivables = $this->ledger->catalog->account($this->book, '1100', 'Receivables', AccountType::Asset, control: true);
    $this->ledger->catalog->dimensionValue($this->entity, 'department', 'west');
    $money = Money::of('100', $this->kes);
    $postings = [new Posting($receivables, Side::Debit, $money, partyId: $party, dimensions: ['department' => 'west']),
        new Posting($this->income, Side::Credit, $money, dimensions: ['department' => 'west'])];
    $journal = $this->ledger->journals->post(new JournalRequest($this->book, 'before-retirement', '2026-01-01', 'Recognized income', $postings));
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $this->ledger->catalog->deleteAccount($this->book, $receivables);
    $this->ledger->catalog->deleteParty($this->entity, $party);
    $this->ledger->catalog->deleteDimensionValue($this->entity, 'department', 'west');
    expect($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['income']->decimal())->toBe('100.00');
    expect($this->ledger->reports->openItems($snapshot, $party)[0]->outstanding->decimal())->toBe('100.00');
    try {
        $this->ledger->journals->post(new JournalRequest($this->book, 'after-retirement', '2026-01-02', 'Invalid new activity', $postings));
        $this->fail('New activity cannot use soft-deleted references.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('soft deleted');
    }
    $this->ledger->journals->reverse($this->book, $journal->id, 'correct-retired-source', '2026-01-02', 'Correct original activity');
    $latest = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    expect($this->ledger->reports->openItems($latest, $party))->toBe([]);
    expect($this->ledger->reports->balanceSheet($latest)['difference']->decimal())->toBe('0.00');
    $this->ledger->catalog->restoreAccount($this->book, $receivables);
    $this->ledger->catalog->restoreParty($this->entity, $party);
    $this->ledger->catalog->restoreDimensionValue($this->entity, 'department', 'west');
    $this->ledger->journals->post(new JournalRequest($this->book, 'restored-activity', '2026-01-03', 'Valid new activity', $postings));
    expect($this->ledger->reports->incomeStatement($this->ledger->reports->snapshot($this->book, '2026-01-31'), '2026-01-01')['income']->decimal())->toBe('100.00');
});

it('soft deletes legal entities and books in dependency order without hiding reports', function () {
    try {
        $this->ledger->catalog->deleteEntity($this->entity);
        $this->fail('A legal entity with live books must not be retired.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('active accounting books');
    }
    $this->ledger->catalog->deleteBook($this->book);
    $this->ledger->catalog->deleteEntity($this->entity);
    expect($this->ledger->reports->balanceSheet($this->ledger->reports->snapshot($this->book, '2026-01-31'))['difference']->decimal())->toBe('0.00');
    try {
        $this->ledger->catalog->restoreBook($this->book);
        $this->fail('An archived parent must be restored first.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('soft-deleted legal entity');
    }
    try {
        $amount = Money::of('1', $this->kes);
        $this->ledger->journals->post(new JournalRequest($this->book, 'archived-post', '2026-01-01', 'Invalid archived activity', [
            new Posting($this->cash, Side::Debit, $amount), new Posting($this->income, Side::Credit, $amount),
        ]));
        $this->fail('A soft-deleted book must reject new postings.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('soft-deleted accounting book');
    }
    $this->ledger->catalog->restoreEntity($this->entity);
    $this->ledger->catalog->restoreBook($this->book);
    expect($this->ledger->catalog->account($this->book, '1200', 'Restored book account', AccountType::Asset)[14])->toBe('7');
});

it('protects catalog timestamps and keeps bookkeeping sequence updates out of business modification timestamps', function () {
    $before = $this->storage->run(Permission::Read, fn () => $this->storage->find('ledger_books', $this->book), $this->book);
    $amount = Money::of('1', $this->kes);
    $this->ledger->journals->post(new JournalRequest($this->book, 'timestamp-post', '2026-01-01', 'Receipt', [
        new Posting($this->cash, Side::Debit, $amount), new Posting($this->income, Side::Credit, $amount),
    ]));
    $after = $this->storage->run(Permission::Read, fn () => $this->storage->find('ledger_books', $this->book), $this->book);
    expect($after->created_at)->toBe($before->created_at);
    expect($after->updated_at)->toBe($before->updated_at);
    $this->ledger->catalog->deleteAccount($this->book, $this->cash);
    $deleted = $this->storage->run(Permission::Read, fn () => $this->storage->find('ledger_accounts', $this->cash, $this->book), $this->book);
    expect($deleted->created_at)->not->toBeNull();
    expect($deleted->updated_at)->not->toBe($deleted->created_at);
    expect($deleted->deleted_at)->not->toBeNull();
    $cutoff = $this->ledger->events->cutoff($this->book);
    $this->ledger->catalog->deleteAccount($this->book, $this->cash);
    expect($this->ledger->events->cutoff($this->book))->toBe($cutoff);
    $this->ledger->catalog->restoreAccount($this->book, $this->cash);
    $restored = $this->storage->run(Permission::Read, fn () => $this->storage->find('ledger_accounts', $this->cash, $this->book), $this->book);
    expect($restored->created_at)->toBe($deleted->created_at);
    expect($restored->deleted_at)->toBeNull();
    $this->storage->run(Permission::Configure, fn () => $this->storage->table('ledger_accounts')->where('id', $this->cash)->update(['created_at' => '2000-01-01']), $this->book);
})->throws(QueryException::class);

it('requires archived group parents to be restored before their children', function () {
    $group = $this->ledger->catalog->account($this->book, '120', 'Group', AccountType::Asset, group: true);
    $child = $this->ledger->catalog->account($this->book, '1200', 'Child', AccountType::Asset, parentId: $group);
    try {
        $this->ledger->catalog->deleteAccount($this->book, $group);
        $this->fail('A group must retain its live children.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('child accounts');
    }
    $this->ledger->catalog->deleteAccount($this->book, $child);
    $this->ledger->catalog->deleteAccount($this->book, $group);
    try {
        $this->ledger->catalog->restoreAccount($this->book, $child);
        $this->fail('A child cannot be restored beneath an archived parent.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('parent account');
    }
    $this->ledger->catalog->restoreAccount($this->book, $group);
    $this->ledger->catalog->restoreAccount($this->book, $child);
});

it('rejects independently retired parties and dimensions on new journals', function (string $reference) {
    $party = $this->ledger->catalog->party($this->entity, 'retired-reference', 'Counterparty');
    $receivables = $this->ledger->catalog->account($this->book, '1100', 'Receivables', AccountType::Asset, control: true);
    $this->ledger->catalog->dimensionValue($this->entity, 'department', 'west');
    if ($reference === 'party') {
        $this->ledger->catalog->deleteParty($this->entity, $party);
    } else {
        $this->ledger->catalog->deleteDimensionValue($this->entity, 'department', 'west');
    }
    $money = Money::of('100', $this->kes);
    $this->ledger->journals->post(new JournalRequest($this->book, 'retired-reference', '2026-01-01', 'Invalid new activity', [
        new Posting($receivables, Side::Debit, $money, partyId: $party, dimensions: ['department' => 'west']),
        new Posting($this->income, Side::Credit, $money, dimensions: ['department' => 'west']),
    ]));
})->with(['party', 'dimension'])->throws(QueryException::class, 'soft');

it('can close a retired book while requiring restoration before reopening its periods', function () {
    $retained = $this->ledger->catalog->account($this->book, '3000', 'Retained earnings', AccountType::Equity);
    $money = Money::of('100', $this->kes);
    $this->ledger->journals->post(new JournalRequest($this->book, 'income-before-retirement', '2026-01-01', 'Recognized income', [
        new Posting($this->cash, Side::Debit, $money), new Posting($this->income, Side::Credit, $money),
    ]));
    $this->ledger->catalog->deleteBook($this->book);
    $this->ledger->yearEnd->close($this->book, 'close-retired-book', '2026-01-01', '2026-12-31', $retained, 'Final closing');
    $position = $this->ledger->reports->balanceSheet($this->ledger->reports->snapshot($this->book, '2026-12-31'));
    expect($position['equity']->decimal())->toBe('100.00');
    expect($position['unclosed_earnings']->decimal())->toBe('0.00');
    $this->ledger->periods->reopen($this->book, $this->period, 'Restore first');
})->throws(QueryException::class, 'soft-deleted accounting book');
