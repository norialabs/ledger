<?php

declare(strict_types=1);

namespace Noria\Ledger;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Catalog\Account;
use Noria\Ledger\Catalog\Book;
use Noria\Ledger\Catalog\Entity;
use Noria\Ledger\Catalog\Party;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Row;
use stdClass;

readonly class Catalog
{
    public function __construct(private ScopedDatabase $db) {}

    public function registerCurrency(Currency $currency): void
    {
        $this->db->run(Permission::Configure, function () use ($currency): void {
            $this->db->connection->table('ledger_currencies')->insertOrIgnore(['tenant_id' => $this->db->scope->tenantId, 'code' => $currency->code, 'scale' => $currency->scale]);
            Assert::that($this->db->currency($currency->code) == $currency, 'A currency cannot be registered with another scale.');
        });
    }

    public function entity(string $name, string $country = 'KE'): string
    {
        Assert::text($name);
        Assert::that(preg_match('/^[A-Z]{2}$/D', $country) === 1, 'Expected a two-letter country code.');

        return $this->db->run(Permission::Configure, function () use ($name, $country): string {
            $id = (string) Str::uuid7();
            $this->db->insert('ledger_entities', ['id' => $id, 'name' => $name, 'country' => $country]);

            return $id;
        });
    }

    public function book(string $entityId, string $name, Currency $currency): string
    {
        Assert::text($name);

        return $this->db->run(Permission::Configure, function () use ($entityId, $name, $currency): string {
            $this->db->find('ledger_entities', $entityId);
            $this->registerCurrency($currency);
            $id = (string) Str::uuid7();
            $this->db->insert('ledger_books', ['id' => $id, 'entity_id' => $entityId, 'name' => $name, 'currency_code' => $currency->code]);

            return $id;
        });
    }

    public function account(
        string $bookId,
        string $code,
        string $name,
        AccountType $type,
        ?Currency $currency = null,
        ?string $parentId = null,
        bool $group = false,
        bool $control = false,
        ?Side $normalSide = null,
        ?string $cashFlow = null,
    ): string {
        Assert::text($code, 64);
        Assert::text($name);

        return $this->db->run(Permission::Configure, function () use ($bookId, $code, $name, $type, $currency, $parentId, $group, $control, $normalSide, $cashFlow): string {
            $this->db->lockBook($bookId);

            if ($currency !== null) {
                $this->registerCurrency($currency);
            }

            $id = (string) Str::uuid7();
            $this->db->insert('ledger_accounts', ['id' => $id, 'book_id' => $bookId, 'code' => $code, 'name' => $name, 'type' => $type->value,
                'normal_side' => ($normalSide ?? $type->normalSide())->value, 'currency_code' => $currency?->code, 'parent_id' => $parentId,
                'is_group' => $group, 'is_control' => $control, 'cash_flow' => $cashFlow]);

            return $id;
        }, $bookId);
    }

    public function setAccountActive(string $bookId, string $accountId, bool $active): void
    {
        $this->db->run(Permission::Configure, function () use ($bookId, $accountId, $active): void {
            $this->db->lockBook($bookId);
            $this->db->find('ledger_accounts', $accountId, $bookId);
            $this->db->table('ledger_accounts')->where('book_id', $bookId)->where('id', $accountId)->update(['active' => $active]);
        }, $bookId);
    }

    public function party(string $entityId, string $reference, string $name): string
    {
        Assert::text($reference, 128);
        Assert::text($name);

        return $this->db->run(Permission::Configure, function () use ($entityId, $reference, $name): string {
            $this->db->find('ledger_entities', $entityId);
            $id = (string) Str::uuid7();
            $this->db->connection->table('ledger_parties')->insertOrIgnore(['tenant_id' => $this->db->scope->tenantId, 'id' => $id, 'entity_id' => $entityId, 'reference' => $reference, 'name' => $name]);
            $existing = $this->db->table('ledger_parties')->where('entity_id', $entityId)->where('reference', $reference)->first();
            Assert::that($existing instanceof stdClass && Row::text($existing, 'name') === $name, 'Party reference already belongs to another name.');
            Assert::that(! $existing instanceof stdClass || Row::nullable($existing, 'deleted_at') === null, 'Restore the soft-deleted party before reusing its reference.');

            return $existing instanceof stdClass ? Row::text($existing, 'id') : $id;
        });
    }

    public function dimensionValue(string $entityId, string $dimension, string $value): void
    {
        Assert::text($dimension, 64);
        Assert::text($value, 128);
        $this->db->run(Permission::Configure, function () use ($entityId, $dimension, $value): void {
            $this->db->find('ledger_entities', $entityId);
            $this->db->connection->table('ledger_dimension_values')->insertOrIgnore(['tenant_id' => $this->db->scope->tenantId, 'entity_id' => $entityId, 'dimension' => $dimension, 'value' => $value]);
            Assert::that($this->db->table('ledger_dimension_values')->where('entity_id', $entityId)->where('dimension', $dimension)->where('value', $value)->whereNull('deleted_at')->exists(), 'Restore the soft-deleted dimension value before reusing it.');
        });
    }

    public function requireDimension(string $bookId, string $accountId, string $dimension): void
    {
        Assert::text($dimension, 64);
        $this->db->run(Permission::Configure, function () use ($bookId, $accountId, $dimension): void {
            $this->db->lockBook($bookId);
            $this->db->find('ledger_accounts', $accountId, $bookId);
            $this->db->insert('ledger_account_dimensions', ['book_id' => $bookId, 'account_id' => $accountId, 'dimension' => $dimension]);
        }, $bookId);
    }

    public function renameEntity(string $entityId, string $name): void
    {
        Assert::text($name);
        $this->db->run(Permission::Configure, function () use ($entityId, $name): void {
            $this->db->find('ledger_entities', $entityId);
            $this->db->table('ledger_entities')->where('id', $entityId)->update(['name' => $name]);
        });
    }

    public function renameBook(string $bookId, string $name): void
    {
        Assert::text($name);
        $this->db->run(Permission::Configure, function () use ($bookId, $name): void {
            $this->db->lockBook($bookId);
            $this->db->table('ledger_books')->where('id', $bookId)->update(['name' => $name]);
        }, $bookId);
    }

    public function renameAccount(string $bookId, string $accountId, string $name): void
    {
        Assert::text($name);
        $this->db->run(Permission::Configure, function () use ($bookId, $accountId, $name): void {
            $this->db->lockBook($bookId);
            $this->db->find('ledger_accounts', $accountId, $bookId);
            $this->db->table('ledger_accounts')->where('book_id', $bookId)->where('id', $accountId)->update(['name' => $name]);
        }, $bookId);
    }

    public function moveAccount(string $bookId, string $accountId, ?string $parentId): void
    {
        $this->db->run(Permission::Configure, function () use ($bookId, $accountId, $parentId): void {
            $this->db->lockBook($bookId);
            $this->db->find('ledger_accounts', $accountId, $bookId);
            if ($parentId !== null) {
                $this->db->find('ledger_accounts', $parentId, $bookId);
            }
            $this->db->table('ledger_accounts')->where('book_id', $bookId)->where('id', $accountId)->update(['parent_id' => $parentId]);
        }, $bookId);
    }

    public function renameParty(string $entityId, string $partyId, string $name): void
    {
        Assert::text($name);
        $this->db->run(Permission::Configure, function () use ($entityId, $partyId, $name): void {
            $this->db->find('ledger_entities', $entityId);
            Assert::that($this->db->table('ledger_parties')->where('entity_id', $entityId)->where('id', Assert::uuid($partyId))->lockForUpdate()->exists(), 'Ledger record not found.');
            $this->db->table('ledger_parties')->where('entity_id', $entityId)->where('id', $partyId)->update(['name' => $name]);
        });
    }

    /** @return list<Entity> */
    public function entities(bool $includeDeleted = false): array
    {
        return $this->db->run(Permission::Read, fn (): array => array_values($this->db->table('ledger_entities')
            ->when(! $includeDeleted, fn (Builder $query) => $query->whereNull('deleted_at'))->orderBy('name')->orderBy('id')->get()
            ->map(fn (stdClass $row): Entity => Entity::fromRow($row))->all()));
    }

    public function findEntity(string $entityId): Entity
    {
        return $this->db->run(Permission::Read, fn (): Entity => Entity::fromRow($this->db->find('ledger_entities', $entityId)));
    }

    /** @return list<Book> */
    public function books(?string $entityId = null, bool $includeDeleted = false): array
    {
        return $this->db->run(Permission::Read, function () use ($entityId, $includeDeleted): array {
            $query = $this->db->table('ledger_books')->when(! $includeDeleted, fn (Builder $query) => $query->whereNull('deleted_at'))->orderBy('name')->orderBy('id');
            if ($entityId !== null) {
                $query->where('entity_id', Assert::uuid($entityId));
            }
            $books = [];
            foreach ($query->get() as $row) {
                if ($this->db->allows(Permission::Read, Row::text($row, 'id'))) {
                    $books[] = Book::fromRow($row);
                }
            }

            return $books;
        });
    }

    public function findBook(string $bookId): Book
    {
        return $this->db->run(Permission::Read, fn (): Book => Book::fromRow($this->db->find('ledger_books', $bookId)), $bookId);
    }

    /** @return list<Account> */
    public function accounts(string $bookId, bool $includeDeleted = false, ?AccountType $type = null, ?string $parentId = null): array
    {
        return $this->db->run(Permission::Read, function () use ($bookId, $includeDeleted, $type, $parentId): array {
            $this->db->find('ledger_books', $bookId);
            $query = $this->db->table('ledger_accounts')->where('book_id', $bookId)
                ->when(! $includeDeleted, fn (Builder $query) => $query->whereNull('deleted_at'))->orderBy('code');
            if ($type !== null) {
                $query->where('type', $type->value);
            }
            if ($parentId !== null) {
                $query->where('parent_id', Assert::uuid($parentId));
            }
            $dimensions = $this->requiredDimensions($bookId);

            return array_values($query->get()->map(fn (stdClass $row): Account => Account::fromRow($row, $dimensions[Row::text($row, 'id')] ?? []))->all());
        }, $bookId);
    }

    public function findAccount(string $bookId, string $accountId): Account
    {
        return $this->db->run(Permission::Read, function () use ($bookId, $accountId): Account {
            $row = $this->db->find('ledger_accounts', $accountId, $bookId);

            return Account::fromRow($row, $this->requiredDimensions($bookId, $accountId)[$accountId] ?? []);
        }, $bookId);
    }

    public function accountByCode(string $bookId, string $code): Account
    {
        Assert::text($code, 64);

        return $this->db->run(Permission::Read, function () use ($bookId, $code): Account {
            $row = $this->db->table('ledger_accounts')->where('book_id', Assert::uuid($bookId))->where('code', $code)->first();
            Assert::that($row instanceof stdClass, 'Ledger record not found.');
            $row = $row instanceof stdClass ? $row : new stdClass;

            return Account::fromRow($row, $this->requiredDimensions($bookId, Row::text($row, 'id'))[Row::text($row, 'id')] ?? []);
        }, $bookId);
    }

    /** @return list<Party> */
    public function parties(string $entityId, ?string $search = null, int $limit = 100, ?string $afterReference = null, bool $includeDeleted = false): array
    {
        Assert::that($limit >= 1 && $limit <= 1000, 'Invalid party page size.');

        return $this->db->run(Permission::Read, function () use ($entityId, $search, $limit, $afterReference, $includeDeleted): array {
            $this->db->find('ledger_entities', $entityId);
            $query = $this->db->table('ledger_parties')->where('entity_id', $entityId)
                ->when(! $includeDeleted, fn (Builder $query) => $query->whereNull('deleted_at'))->orderBy('reference')->limit($limit);
            if ($search !== null) {
                $pattern = '%'.addcslashes(Assert::text($search), '\%_').'%';
                $query->where(fn (Builder $inner) => $inner->where('reference', 'ilike', $pattern)->orWhere('name', 'ilike', $pattern));
            }
            if ($afterReference !== null) {
                $query->where('reference', '>', $afterReference);
            }

            return array_values($query->get()->map(fn (stdClass $row): Party => Party::fromRow($row))->all());
        });
    }

    public function findParty(string $entityId, string $partyId): Party
    {
        return $this->db->run(Permission::Read, function () use ($entityId, $partyId): Party {
            $row = $this->db->table('ledger_parties')->where('entity_id', Assert::uuid($entityId))->where('id', Assert::uuid($partyId))->first();
            Assert::that($row instanceof stdClass, 'Ledger record not found.');

            return Party::fromRow($row instanceof stdClass ? $row : new stdClass);
        });
    }

    public function partyByReference(string $entityId, string $reference): Party
    {
        return $this->db->run(Permission::Read, function () use ($entityId, $reference): Party {
            $row = $this->db->table('ledger_parties')->where('entity_id', Assert::uuid($entityId))->where('reference', Assert::text($reference, 128))->first();
            Assert::that($row instanceof stdClass, 'Ledger record not found.');

            return Party::fromRow($row instanceof stdClass ? $row : new stdClass);
        });
    }

    /** @return array<string, list<string>> */
    public function dimensionValues(string $entityId, ?string $dimension = null, bool $includeDeleted = false): array
    {
        return $this->db->run(Permission::Read, function () use ($entityId, $dimension, $includeDeleted): array {
            $this->db->find('ledger_entities', $entityId);
            $query = $this->db->table('ledger_dimension_values')->where('entity_id', $entityId)
                ->when(! $includeDeleted, fn (Builder $query) => $query->whereNull('deleted_at'))->orderBy('dimension')->orderBy('value');
            if ($dimension !== null) {
                $query->where('dimension', Assert::text($dimension, 64));
            }
            $result = [];
            foreach ($query->get() as $row) {
                $result[Row::text($row, 'dimension')][] = Row::text($row, 'value');
            }

            return $result;
        });
    }

    public function deleteEntity(string $entityId): void
    {
        $this->setDeleted('ledger_entities', ['id' => Assert::uuid($entityId)], true);
    }

    public function restoreEntity(string $entityId): void
    {
        $this->setDeleted('ledger_entities', ['id' => Assert::uuid($entityId)], false);
    }

    public function deleteBook(string $bookId): void
    {
        $this->setDeleted('ledger_books', ['id' => Assert::uuid($bookId)], true, $bookId);
    }

    public function restoreBook(string $bookId): void
    {
        $this->setDeleted('ledger_books', ['id' => Assert::uuid($bookId)], false, $bookId);
    }

    public function deleteAccount(string $bookId, string $accountId): void
    {
        $this->setDeleted('ledger_accounts', ['id' => Assert::uuid($accountId), 'book_id' => Assert::uuid($bookId)], true, $bookId);
    }

    public function restoreAccount(string $bookId, string $accountId): void
    {
        $this->setDeleted('ledger_accounts', ['id' => Assert::uuid($accountId), 'book_id' => Assert::uuid($bookId)], false, $bookId);
    }

    public function deleteParty(string $entityId, string $partyId): void
    {
        $this->setDeleted('ledger_parties', ['id' => Assert::uuid($partyId), 'entity_id' => Assert::uuid($entityId)], true);
    }

    public function restoreParty(string $entityId, string $partyId): void
    {
        $this->setDeleted('ledger_parties', ['id' => Assert::uuid($partyId), 'entity_id' => Assert::uuid($entityId)], false);
    }

    public function deleteDimensionValue(string $entityId, string $dimension, string $value): void
    {
        $this->setDeleted('ledger_dimension_values', ['entity_id' => Assert::uuid($entityId), 'dimension' => Assert::text($dimension, 64), 'value' => Assert::text($value, 128)], true);
    }

    public function restoreDimensionValue(string $entityId, string $dimension, string $value): void
    {
        $this->setDeleted('ledger_dimension_values', ['entity_id' => Assert::uuid($entityId), 'dimension' => Assert::text($dimension, 64), 'value' => Assert::text($value, 128)], false);
    }

    /** @return array<string, list<string>> */
    private function requiredDimensions(string $bookId, ?string $accountId = null): array
    {
        $query = $this->db->table('ledger_account_dimensions')->where('book_id', $bookId)->orderBy('dimension');
        if ($accountId !== null) {
            $query->where('account_id', Assert::uuid($accountId));
        }
        $result = [];
        foreach ($query->get() as $row) {
            $result[Row::text($row, 'account_id')][] = Row::text($row, 'dimension');
        }

        return $result;
    }

    /** @param array<string, string> $identity */
    private function setDeleted(string $table, array $identity, bool $deleted, ?string $bookId = null): void
    {
        $this->db->run(Permission::Configure, function () use ($table, $identity, $deleted, $bookId): void {
            if ($bookId !== null) {
                $this->db->lockBook($bookId);
            }
            $query = $this->db->table($table)->where($identity);
            $row = (clone $query)->lockForUpdate()->first();
            Assert::that($row instanceof stdClass, 'Catalog record not found.');
            if ($row instanceof stdClass && (Row::nullable($row, 'deleted_at') !== null) !== $deleted) {
                $query->update(['deleted_at' => $deleted ? $this->db->connection->raw('clock_timestamp()') : null]);
            }
        }, $bookId);
    }
}
