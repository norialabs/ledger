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
use Noria\Ledger\Tax\TaxRule;

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

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['name' => $this->name, 'version' => $this->version, 'accounts' => $this->accounts, 'roles' => $this->roles,
            'tax_rules' => $this->taxRules, 'statements' => $this->statements, 'settings' => $this->settings,
            'country' => $this->country, 'valid_from' => $this->validFrom, 'valid_until' => $this->validUntil,
            ...($this->reportingMapping === null ? [] : ['reporting_mapping' => $this->reportingMapping])];
    }
}
