<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Noria\Ledger\Access\Authorizer;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Access\Scope;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Ledger;

it('lists the chart in code order with hierarchy, classification and required dimensions', function () {
    $current = $this->ledger->catalog->account($this->book, '1', 'Assets', AccountType::Asset, group: true);
    $receivable = $this->ledger->catalog->account($this->book, '1100', 'Receivables', AccountType::Asset, parentId: $current, control: true);
    $this->ledger->catalog->dimensionValue($this->entity, 'campus', 'east');
    $this->ledger->catalog->requireDimension($this->book, $receivable, 'campus');

    $accounts = $this->ledger->catalog->accounts($this->book);
    expect(array_map(fn ($account) => $account->code, $accounts))->toBe(['1', '1000', '1100', '4000']);
    $found = $this->ledger->catalog->findAccount($this->book, $receivable);
    expect($found->parentId)->toBe($current)
        ->and($found->control)->toBeTrue()
        ->and($found->normalSide)->toBe(Side::Debit)
        ->and($found->requiredDimensions)->toBe(['campus'])
        ->and($this->ledger->catalog->accountByCode($this->book, '4000')->id)->toBe($this->income)
        ->and(array_map(fn ($account) => $account->code, $this->ledger->catalog->accounts($this->book, type: AccountType::Income)))->toBe(['4000'])
        ->and(array_map(fn ($account) => $account->id, $this->ledger->catalog->accounts($this->book, parentId: $current)))->toBe([$receivable]);
});

it('hides soft-deleted catalog records unless asked and pages parties by reference', function () {
    $retired = $this->ledger->catalog->account($this->book, '6000', 'Old expense', AccountType::Expense);
    $this->ledger->catalog->deleteAccount($this->book, $retired);
    foreach (['C-003' => 'Charlie', 'C-001' => 'Alpha', 'C-002' => 'Bravo 100%'] as $reference => $name) {
        $this->ledger->catalog->party($this->entity, $reference, $name);
    }
    $this->ledger->catalog->deleteParty($this->entity, $this->ledger->catalog->partyByReference($this->entity, 'C-003')->id);

    expect(array_map(fn ($account) => $account->code, $this->ledger->catalog->accounts($this->book)))->not->toContain('6000')
        ->and($this->ledger->catalog->accounts($this->book, includeDeleted: true))->toHaveCount(3)
        ->and(array_map(fn ($party) => $party->reference, $this->ledger->catalog->parties($this->entity)))->toBe(['C-001', 'C-002'])
        ->and(array_map(fn ($party) => $party->reference, $this->ledger->catalog->parties($this->entity, limit: 1, afterReference: 'C-001', includeDeleted: true)))->toBe(['C-002'])
        ->and(array_map(fn ($party) => $party->name, $this->ledger->catalog->parties($this->entity, search: '100%')))->toBe(['Bravo 100%'])
        ->and($this->ledger->catalog->parties($this->entity, search: '_'))->toBe([])
        ->and($this->ledger->catalog->findParty($this->entity, $this->ledger->catalog->partyByReference($this->entity, 'C-003')->id)->deleted)->toBeTrue();
});

it('lists entities, books, periods and dimension values for the current tenant only', function () {
    $this->ledger->catalog->dimensionValue($this->entity, 'campus', 'west');
    $this->ledger->catalog->dimensionValue($this->entity, 'campus', 'east');
    $this->ledger->catalog->dimensionValue($this->entity, 'fund', 'general');
    $next = $this->ledger->periods->open($this->book, '2027', '2027-01-01', '2027-12-31');
    $other = $this->application()->make(Ledger::class)->for(new Scope((string) Str::uuid7(), 'other-tenant'));
    $other->catalog->entity('Other tenant');

    expect(array_map(fn ($entity) => $entity->name, $this->ledger->catalog->entities()))->toBe(['Reference School'])
        ->and(array_map(fn ($book) => $book->name, $this->ledger->catalog->books($this->entity)))->toBe(['Statutory'])
        ->and($this->ledger->catalog->findBook($this->book)->currencyCode)->toBe('KES')
        ->and(array_map(fn ($period) => $period->id, $this->ledger->periods->list($this->book)))->toBe([$this->period, $next])
        ->and($this->ledger->periods->covering($this->book, '2027-03-01')?->name)->toBe('2027')
        ->and($this->ledger->periods->covering($this->book, '2030-01-01'))->toBeNull()
        ->and($this->ledger->catalog->dimensionValues($this->entity))->toBe(['campus' => ['east', 'west'], 'fund' => ['general']]);
});

it('omits books the host does not let the actor read', function () {
    $hidden = $this->ledger->catalog->book($this->entity, 'Management', $this->kes);
    $ledger = new Ledger($this->connection(), new class($hidden) implements Authorizer
    {
        public function __construct(private string $hidden) {}

        public function allows(Scope $scope, Permission $permission, ?string $bookId = null): bool
        {
            return $bookId !== $this->hidden;
        }
    });

    expect(array_map(fn ($book) => $book->name, $ledger->for($this->scope)->catalog->books()))->toBe(['Statutory']);
});

it('renames catalog records without changing their identity and audits each change', function () {
    $party = $this->ledger->catalog->party($this->entity, 'G-1', 'Guardian');
    $this->ledger->catalog->renameEntity($this->entity, 'Reference Academy');
    $this->ledger->catalog->renameBook($this->book, 'Statutory IFRS');
    $this->ledger->catalog->renameAccount($this->book, $this->cash, 'Main bank');
    $this->ledger->catalog->renameParty($this->entity, $party, 'Guardian (primary)');

    expect($this->ledger->catalog->findEntity($this->entity)->name)->toBe('Reference Academy')
        ->and($this->ledger->catalog->findBook($this->book)->name)->toBe('Statutory IFRS')
        ->and($this->ledger->catalog->findAccount($this->book, $this->cash)->name)->toBe('Main bank')
        ->and($this->ledger->catalog->findParty($this->entity, $party)->name)->toBe('Guardian (primary)')
        ->and($this->storage->run(Permission::Read, fn () => $this->storage->table('ledger_audit_events')->where('operation', 'UPDATE')
            ->whereIn('table_name', ['ledger_entities', 'ledger_books', 'ledger_accounts', 'ledger_parties'])->whereRaw("jsonb_exists(after_value, 'name')")->count()))->toBeGreaterThanOrEqual(4);
});

it('moves an account between groups of the same type but never beneath its own descendant', function () {
    $assets = $this->ledger->catalog->account($this->book, '1', 'Assets', AccountType::Asset, group: true);
    $current = $this->ledger->catalog->account($this->book, '11', 'Current', AccountType::Asset, parentId: $assets, group: true);
    $this->ledger->catalog->moveAccount($this->book, $this->cash, $current);
    expect($this->ledger->catalog->findAccount($this->book, $this->cash)->parentId)->toBe($current);
    $this->ledger->catalog->moveAccount($this->book, $this->cash, null);
    expect($this->ledger->catalog->findAccount($this->book, $this->cash)->parentId)->toBeNull();

    expect(fn () => $this->ledger->catalog->moveAccount($this->book, $assets, $current))->toThrow(QueryException::class, 'beneath its own descendant');
    $income = $this->ledger->catalog->account($this->book, '4', 'Revenue', AccountType::Income, group: true);
    expect(fn () => $this->ledger->catalog->moveAccount($this->book, $this->cash, $income))->toThrow(QueryException::class, 'group of the same type');
});

it('keeps account classification immutable when edited by direct SQL', function () {
    $this->storage->run(Permission::Configure, fn () => $this->storage->table('ledger_accounts')->where('id', $this->income)->update(['type' => 'expense']));
})->throws(QueryException::class, 'Account classification cannot change');

it('rejects reading a catalog record from another entity', function () {
    $otherEntity = $this->ledger->catalog->entity('Other school');
    $party = $this->ledger->catalog->party($otherEntity, 'X-1', 'Elsewhere');
    $this->ledger->catalog->findParty($this->entity, $party);
})->throws(LedgerException::class, 'not found');
