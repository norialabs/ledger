<?php

declare(strict_types=1);

namespace Noria\Ledger\Configuration;

use JsonSerializable;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Reporting\ReportingMapping;
use Noria\Ledger\Reporting\StatementDefinition;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Canonical;
use Noria\Ledger\Support\Row;
use Noria\Ledger\Tax\TaxRule;
use stdClass;

final readonly class Pack implements JsonSerializable
{
    /** @param list<array{code: string, name: string, type: string, parent?: string, group?: bool, control?: bool, normal_side?: string, cash_flow?: string, currency?: string, dimensions?: list<string>}> $accounts
     * @param  array<string, string>  $roles
     * @param  list<TaxRule>  $taxRules
     * @param  list<StatementDefinition>  $statements
     * @param  array<string, mixed>  $settings
     */
    public function __construct(public string $name, public string $version, public array $accounts = [], public array $roles = [],
        public array $taxRules = [], public array $statements = [], public array $settings = [], public ?string $country = null,
        public string $validFrom = '0001-01-01', public ?string $validUntil = null, public ?ReportingMapping $reportingMapping = null)
    {
        Assert::text($name, 128);
        Assert::text($version, 128);
        Assert::date($validFrom);
        if ($validUntil !== null) {
            Assert::date($validUntil);
            Assert::that($validUntil >= $validFrom, 'Configuration validity dates are reversed.');
        }
        Assert::that($country === null || preg_match('/^[A-Z]{2}$/D', $country) === 1, 'Expected a two-letter configuration country.');
        Assert::that(array_is_list($accounts) && array_is_list($taxRules) && array_is_list($statements), 'Configuration definitions must be ordered lists.');
        $codes = [];
        foreach ($accounts as $account) {
            Assert::text($account['code'], 64);
            Assert::text($account['name']);
            AccountType::from($account['type']);
            if (isset($account['normal_side'])) {
                Side::from($account['normal_side']);
            }
            Assert::that(array_diff(array_keys($account), ['code', 'name', 'type', 'parent', 'group', 'control', 'normal_side', 'cash_flow', 'currency', 'dimensions']) === [], 'Unknown chart account setting.');
            Assert::that(! isset($codes[$account['code']]), 'Duplicate configuration account code.');
            $codes[$account['code']] = true;
        }
        foreach ($roles as $role => $code) {
            Assert::text($role, 128);
            Assert::text($code, 64);
        }
        $taxCodes = [];
        foreach ($taxRules as $rule) {
            Assert::that(! isset($taxCodes[$rule->code]), 'Duplicate configuration tax code.');
            $taxCodes[$rule->code] = true;
        }
        $statementNames = [];
        foreach ($statements as $statement) {
            Assert::that(! isset($statementNames[$statement->name]), 'Duplicate configuration statement name.');
            $statementNames[$statement->name] = true;
        }
        Assert::that($reportingMapping === null || ! isset($statementNames[$reportingMapping->name]), 'A reporting mapping cannot share a statement name.');
        Canonical::json($this);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $row = (object) $data;

        return new self(Row::text($row, 'name'), Row::text($row, 'version'), Row::objects($row->accounts, self::account(...)), Row::stringMap($row->roles),
            Row::objects($row->tax_rules, fn (stdClass $rule): TaxRule => new TaxRule(Row::text($rule, 'code'), Row::text($rule, 'rate'), Row::text($rule, 'valid_from'),
                Row::nullable($rule, 'valid_until'), Row::text($rule, 'policy_version'), Row::boolean($rule, 'inclusive'), Row::boolean($rule, 'compound'))),
            Row::objects($row->statements, fn (stdClass $statement): StatementDefinition => StatementDefinition::fromArray((array) $statement)),
            Row::map($row->settings, fn (mixed $value): mixed => $value), Row::nullable($row, 'country'), Row::text($row, 'valid_from'), Row::nullable($row, 'valid_until'),
            isset($row->reporting_mapping) ? ReportingMapping::fromArray((array) Row::object($row->reporting_mapping)) : null);
    }

    /** @return array{code: string, name: string, type: string, parent?: string, group?: bool, control?: bool, normal_side?: string, cash_flow?: string, currency?: string, dimensions?: list<string>} */
    private static function account(stdClass $row): array
    {
        $account = ['code' => Row::text($row, 'code'), 'name' => Row::text($row, 'name'), 'type' => Row::text($row, 'type')];
        if (isset($row->parent)) {
            $account['parent'] = Row::text($row, 'parent');
        }
        if (isset($row->group)) {
            $account['group'] = Row::boolean($row, 'group');
        }
        if (isset($row->control)) {
            $account['control'] = Row::boolean($row, 'control');
        }
        if (isset($row->normal_side)) {
            $account['normal_side'] = Row::text($row, 'normal_side');
        }
        if (isset($row->cash_flow)) {
            $account['cash_flow'] = Row::text($row, 'cash_flow');
        }
        if (isset($row->currency)) {
            $account['currency'] = Row::text($row, 'currency');
        }
        if (isset($row->dimensions)) {
            $account['dimensions'] = Row::strings($row->dimensions);
        }

        return $account;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['name' => $this->name, 'version' => $this->version, 'accounts' => $this->accounts, 'roles' => $this->roles,
            'tax_rules' => $this->taxRules, 'statements' => $this->statements, 'settings' => $this->settings,
            'country' => $this->country, 'valid_from' => $this->validFrom, 'valid_until' => $this->validUntil,
            ...($this->reportingMapping === null ? [] : ['reporting_mapping' => $this->reportingMapping])];
    }
}
