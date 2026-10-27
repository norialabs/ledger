<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger;

use Illuminate\Support\Str;
use NoriaLabs\Ledger\Access\Permission;
use NoriaLabs\Ledger\Accounting\AccountType;
use NoriaLabs\Ledger\Accounting\Side;
use NoriaLabs\Ledger\Money\Currency;
use NoriaLabs\Ledger\Storage\ScopedDatabase;
use NoriaLabs\Ledger\Support\Assert;
use NoriaLabs\Ledger\Support\Row;
use stdClass;

final readonly class Catalog
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
            $id = (string) Str::uuid();
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
            $id = (string) Str::uuid();
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

            $id = (string) Str::uuid();
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
            $id = (string) Str::uuid();
            $this->db->connection->table('ledger_parties')->insertOrIgnore(['tenant_id' => $this->db->scope->tenantId, 'id' => $id, 'entity_id' => $entityId, 'reference' => $reference, 'name' => $name]);
            $existing = $this->db->table('ledger_parties')->where('entity_id', $entityId)->where('reference', $reference)->first();
            Assert::that($existing instanceof stdClass && Row::text($existing, 'name') === $name, 'Party reference already belongs to another name.');

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
}
