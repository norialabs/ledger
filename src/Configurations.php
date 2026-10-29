<?php

declare(strict_types=1);

namespace Noria\Ledger;

use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Configuration\Pack;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Reporting\StatementDefinition;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Canonical;
use Noria\Ledger\Support\Row;
use Noria\Ledger\Tax\TaxRule;
use stdClass;

readonly class Configurations
{
    public function __construct(private ScopedDatabase $db, private Catalog $catalog) {}

    public function install(string $bookId, Pack $pack): string
    {
        return $this->db->run(Permission::Configure, function () use ($bookId, $pack): string {
            $book = $this->db->lockBook($bookId);
            $entity = $this->db->find('ledger_entities', Row::text($book, 'entity_id'));
            Assert::that($pack->country === null || $pack->country === Row::text($entity, 'country'), 'Configuration country differs from the legal entity.');
            $json = Canonical::json($pack);
            $hash = hash('sha256', $json);
            $existing = $this->db->table('ledger_configurations')->where('book_id', $bookId)->where('name', $pack->name)->where('version', $pack->version)->first();
            if ($existing instanceof stdClass) {
                Assert::that(hash_equals(Canonical::hash(Row::jsonObject($existing, 'definition_json')), $hash), 'Configuration version already contains different content.');

                return Row::text($existing, 'id');
            }

            $accounts = [];
            foreach ($this->db->table('ledger_accounts')->where('book_id', $bookId)->get() as $account) {
                $accounts[Row::text($account, 'code')] = $account;
            }
            $pending = $pack->accounts;
            $ids = [];
            while ($pending !== []) {
                $unresolved = [];
                foreach ($pending as $definition) {
                    $parentCode = $definition['parent'] ?? null;
                    if ($parentCode !== null && ! isset($accounts[$parentCode])) {
                        $unresolved[] = $definition;

                        continue;
                    }
                    $parent = $parentCode === null ? null : Row::text($accounts[$parentCode], 'id');
                    $type = AccountType::from($definition['type']);
                    $normalSide = isset($definition['normal_side']) ? Side::from($definition['normal_side']) : $type->normalSide();
                    $currency = isset($definition['currency']) ? $this->currency($definition['currency']) : null;
                    $group = $definition['group'] ?? false;
                    $control = $definition['control'] ?? false;
                    $cashFlow = $definition['cash_flow'] ?? null;
                    $code = $definition['code'];
                    if (isset($accounts[$code])) {
                        $account = $accounts[$code];
                        Assert::that(Row::text($account, 'type') === $type->value && Row::text($account, 'normal_side') === $normalSide->value
                            && Row::nullable($account, 'parent_id') === $parent && Row::boolean($account, 'is_group') === $group
                            && Row::boolean($account, 'is_control') === $control && Row::nullable($account, 'cash_flow') === $cashFlow
                            && Row::nullable($account, 'currency_code') === $currency?->code, 'Existing account conflicts with configuration: '.$code);
                        $id = Row::text($account, 'id');
                    } else {
                        $id = $this->catalog->account($bookId, $code, $definition['name'], $type, $currency, $parent, $group, $control, $normalSide, $cashFlow);
                        $accounts[$code] = $this->db->find('ledger_accounts', $id, $bookId);
                    }
                    $ids[$code] = $id;
                    foreach ($definition['dimensions'] ?? [] as $dimension) {
                        if (! $this->db->table('ledger_account_dimensions')->where('book_id', $bookId)->where('account_id', $id)->where('dimension', $dimension)->exists()) {
                            $this->catalog->requireDimension($bookId, $id, $dimension);
                        }
                    }
                }
                Assert::that(count($unresolved) < count($pending), 'Chart parents are missing or form a cycle.');
                $pending = $unresolved;
            }

            $roles = [];
            foreach ($pack->roles as $role => $code) {
                Assert::that(isset($accounts[$code]) && ! Row::boolean($accounts[$code], 'is_group') && Row::nullable($accounts[$code], 'deleted_at') === null, 'An account role requires an existing live posting account: '.$role);
                $roles[$role] = Row::text($accounts[$code], 'id');
            }
            foreach ($pack->statements as $statement) {
                $statement->reportingMapping?->validateAccounts($accounts);
                foreach ($statement->rows as $row) {
                    foreach ($row['accounts'] ?? [] as $code) {
                        Assert::that(isset($accounts[$code]), 'A configuration statement references an unknown account: '.$code);
                    }
                }
            }
            $pack->reportingMapping?->validateAccounts($accounts);
            $id = (string) Str::uuid7();
            $this->db->insert('ledger_configurations', ['id' => $id, 'book_id' => $bookId, 'name' => $pack->name, 'version' => $pack->version,
                'definition_json' => $json, 'account_ids' => json_encode((object) $ids, JSON_THROW_ON_ERROR),
                'roles' => json_encode((object) $roles, JSON_THROW_ON_ERROR)]);

            return $id;
        }, $bookId);
    }

    /** @return array{definition: Pack, definition_json: string, definition_hash: string, content_hash: string, roles: array<string, string>} */
    public function get(string $bookId, string $configurationId, ?string $effectiveOn = null): array
    {
        if ($effectiveOn !== null) {
            Assert::date($effectiveOn);
        }

        return $this->db->run(Permission::Read, function () use ($bookId, $configurationId, $effectiveOn): array {
            $row = $this->db->find('ledger_configurations', $configurationId, $bookId);
            $json = Row::text($row, 'definition_json');
            Assert::that(hash_equals(Row::text($row, 'definition_hash'), hash('sha256', $json)), 'Configuration evidence hash does not match.');
            $data = Row::jsonObject($row, 'definition_json');
            $definition = Pack::fromArray($data);
            Assert::that($effectiveOn === null || ($effectiveOn >= $definition->validFrom && ($definition->validUntil === null || $effectiveOn <= $definition->validUntil)), 'Configuration is not effective on the requested date.');
            $roles = [];
            foreach (Row::json($row, 'roles') as $role => $id) {
                Assert::that(is_string($role) && is_string($id), 'Invalid stored configuration role.');
                $roles[$role] = Assert::uuid($id);
            }

            return ['definition' => $definition, 'definition_json' => $json, 'definition_hash' => Row::text($row, 'definition_hash'), 'content_hash' => Canonical::hash($data), 'roles' => $roles];
        }, $bookId);
    }

    public function account(string $bookId, string $configurationId, string $role): string
    {
        $roles = $this->get($bookId, $configurationId)['roles'];
        Assert::that(isset($roles[$role]), 'Unknown configuration account role: '.$role);

        return $roles[$role];
    }

    /** @return list<TaxRule> */
    public function taxRules(string $bookId, string $configurationId, string $effectiveOn): array
    {
        return $this->get($bookId, $configurationId, $effectiveOn)['definition']->taxRules;
    }

    private function currency(string $code): Currency
    {
        $registered = $this->db->table('ledger_currencies')->where('code', $code)->first();

        return $registered instanceof stdClass ? $this->db->currency($code) : Currency::iso($code);
    }

    public function statement(string $bookId, string $configurationId, string $name): StatementDefinition
    {
        $pack = $this->get($bookId, $configurationId)['definition'];
        foreach ($pack->statements as $definition) {
            if ($definition->name === $name) {
                return $definition;
            }
        }
        if ($pack->reportingMapping?->name === $name) {
            return $pack->reportingMapping->definition();
        }

        throw new LedgerException('Unknown configuration statement: '.$name);
    }
}
