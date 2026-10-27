<?php

declare(strict_types=1);

namespace Noria\Ledger;

use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\Side;
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
