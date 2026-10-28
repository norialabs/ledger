<?php

declare(strict_types=1);

namespace Noria\Ledger\Reporting;

use JsonSerializable;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Row;
use stdClass;

final readonly class ReportingMapping implements JsonSerializable
{
    public const PROFIT_OR_LOSS = ['operating', 'investing', 'financing', 'income_taxes', 'discontinued_operations'];

    public const OCI = ['recyclable', 'non_recyclable'];

    /** @param array<array-key, array{profit_or_loss?: string, oci?: string, equity_component?: string}> $accounts
     * @param  array<string, string>  $equityComponents
     * @param  array<string, array{label: string, subtotal: string, review_reference: string, adjustments: array<string, array{label: string, accounts: non-empty-list<string>, coefficient: string}>}>  $performanceMeasures
     */
    public function __construct(
        public string $name,
        public string $version,
        public string $mainBusinessActivity,
        public array $accounts,
        public bool $financingSubtotalRequired,
        public array $equityComponents = [],
        public array $performanceMeasures = [],
    ) {
        Assert::text($name, 128);
        Assert::text($version, 128);
        Assert::text($mainBusinessActivity);
        Assert::that($accounts !== [] && count($accounts) <= 100000, 'A reporting mapping requires between 1 and 100,000 accounts.');
        foreach ($equityComponents as $key => $label) {
            self::key($key);
            Assert::text($label);
        }
        foreach ($accounts as $code => $classification) {
            Assert::text((string) $code, 64);
            Assert::that($classification !== [] && array_diff(array_keys($classification), ['profit_or_loss', 'oci', 'equity_component']) === [], 'Invalid reporting account classification.');
            Assert::that(! isset($classification['profit_or_loss'], $classification['oci']), 'An account cannot be both profit or loss and OCI.');
            if (isset($classification['profit_or_loss'])) {
                Assert::that(in_array($classification['profit_or_loss'], self::PROFIT_OR_LOSS, true), 'Invalid profit-or-loss category.');
            }
            if (isset($classification['oci'])) {
                Assert::that(in_array($classification['oci'], self::OCI, true), 'Invalid OCI category.');
            }
            if (isset($classification['equity_component'])) {
                Assert::that(isset($equityComponents[$classification['equity_component']]), 'Unknown equity component.');
            }
        }
        Assert::that(count($performanceMeasures) <= 100, 'A reporting mapping supports at most 100 performance measures.');
        $rowCount = 12;
        foreach ($performanceMeasures as $key => $measure) {
            self::key($key);
            Assert::text($measure['label']);
            Assert::text($measure['review_reference']);
            Assert::that(array_diff(array_keys($measure), ['label', 'subtotal', 'review_reference', 'adjustments']) === [], 'Unknown performance measure setting.');
            Assert::that(in_array($measure['subtotal'], ['operating_profit', ...($financingSubtotalRequired ? ['profit_before_financing_and_income_taxes'] : []), 'profit_before_income_taxes', 'profit_from_continuing_operations', 'profit'], true), 'A performance measure requires a supported IFRS subtotal.');
            Assert::that($measure['adjustments'] !== [] && count($measure['adjustments']) <= 20, 'A performance measure requires between 1 and 20 adjustments.');
            $rowCount += 1 + count($measure['adjustments']);
            foreach ($measure['adjustments'] as $adjustmentKey => $adjustment) {
                self::key($adjustmentKey);
                Assert::text($adjustment['label']);
                Assert::that(array_diff(array_keys($adjustment), ['label', 'accounts', 'coefficient']) === [], 'Unknown performance measure adjustment setting.');
                Assert::that(in_array($adjustment['coefficient'], ['1', '-1'], true), 'A performance measure adjustment coefficient must be 1 or -1.');
                Assert::that(array_is_list($adjustment['accounts']) && $adjustment['accounts'] !== [] && count(array_unique($adjustment['accounts'])) === count($adjustment['accounts']), 'Performance measure adjustment accounts must be a non-empty unique list.');
                foreach ($adjustment['accounts'] as $code) {
                    Assert::that(isset($accounts[$code]['profit_or_loss']), 'Performance measure adjustments require mapped profit-or-loss accounts.');
                }
            }
        }
        Assert::that($rowCount <= 1000, 'Reporting measures exceed the statement row limit.');
    }

    private static function key(string $key): void
    {
        Assert::that(preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,31}$/D', $key) === 1, 'Invalid reporting component or performance measure key.');
    }

    /** @param array<array-key, stdClass> $accounts */
    public function validateAccounts(array $accounts): void
    {
        foreach ($this->accounts as $code => $classification) {
            Assert::that(isset($accounts[$code]), 'Unknown reporting account code: '.$code);
            $account = $accounts[$code];
            Assert::that(! Row::boolean($account, 'is_group'), 'Reporting mappings require explicit posting accounts: '.$code);
            $type = Row::text($account, 'type');
            if (isset($classification['profit_or_loss'])) {
                Assert::that(in_array($type, ['income', 'expense'], true), 'Profit-or-loss mappings require income or expense accounts: '.$code);
            }
            if (isset($classification['oci'])) {
                Assert::that(in_array($type, ['income', 'expense', 'equity'], true), 'OCI mappings require income, expense or equity accounts: '.$code);
                Assert::that($classification['oci'] !== 'recyclable' || $type === 'equity', 'Recyclable OCI requires an equity reserve account: '.$code);
            }
            if (isset($classification['equity_component'])) {
                Assert::that($type === 'equity', 'Equity components require equity accounts: '.$code);
            }
            Assert::that($type !== 'equity' || isset($classification['equity_component']), 'Mapped equity accounts require an equity component: '.$code);
        }
    }

    public function definition(): StatementDefinition
    {
        $rows = [];
        foreach (self::PROFIT_OR_LOSS as $category) {
            $codes = [];
            foreach ($this->accounts as $code => $classification) {
                if (($classification['profit_or_loss'] ?? null) === $category) {
                    $codes[] = (string) $code;
                }
            }
            sort($codes, SORT_STRING);
            $rows[$category] = ['label' => ucwords(str_replace('_', ' ', $category)), 'accounts' => $codes, 'sign' => 'credit', 'include_closing' => false];
        }
        $rows['operating_profit'] = ['label' => 'Operating profit', 'terms' => ['operating' => '1']];
        if ($this->financingSubtotalRequired) {
            $rows['profit_before_financing_and_income_taxes'] = ['label' => 'Profit before financing and income taxes', 'terms' => ['operating' => '1', 'investing' => '1']];
        }
        $rows['profit_before_income_taxes'] = ['label' => 'Profit before income taxes', 'terms' => ['operating' => '1', 'investing' => '1', 'financing' => '1']];
        $rows['profit_from_continuing_operations'] = ['label' => 'Profit from continuing operations', 'terms' => ['profit_before_income_taxes' => '1', 'income_taxes' => '1']];
        $rows['profit'] = ['label' => 'Profit', 'terms' => ['profit_from_continuing_operations' => '1', 'discontinued_operations' => '1']];
        foreach (self::OCI as $category) {
            $codes = [];
            foreach ($this->accounts as $code => $classification) {
                if (($classification['oci'] ?? null) === $category) {
                    $codes[] = (string) $code;
                }
            }
            sort($codes, SORT_STRING);
            $rows['oci_'.$category] = ['label' => $category === 'recyclable' ? 'OCI that may subsequently be reclassified to profit or loss' : 'OCI that will not be reclassified to profit or loss', 'accounts' => $codes, 'sign' => 'credit', 'include_closing' => false];
        }
        $measures = $this->performanceMeasures;
        ksort($measures, SORT_STRING);
        foreach ($measures as $key => $measure) {
            $terms = [$measure['subtotal'] => '1'];
            $adjustments = $measure['adjustments'];
            ksort($adjustments, SORT_STRING);
            foreach ($adjustments as $adjustmentKey => $adjustment) {
                $row = 'mpm.'.$key.'.'.$adjustmentKey;
                $rows[$row] = ['label' => $adjustment['label'], 'accounts' => $adjustment['accounts'], 'sign' => $adjustment['coefficient'] === '1' ? 'credit' : 'debit', 'include_closing' => false];
                $terms[$row] = '1';
            }
            $rows['mpm.'.$key] = ['label' => $measure['label'], 'terms' => $terms];
        }

        return new StatementDefinition($this->name, $this->version, $rows, ['income', 'expense'], $this);
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        Assert::that(array_diff(array_keys($data), ['name', 'version', 'main_business_activity', 'accounts', 'financing_subtotal_required', 'equity_components', 'performance_measures']) === [], 'Unknown reporting mapping setting.');
        Assert::that(is_bool($data['financing_subtotal_required'] ?? null), 'Reporting policy requires an explicit financing-subtotal presentation decision.');
        $accounts = [];
        $definitions = $data['accounts'] ?? null;
        Assert::that(is_array($definitions), 'Invalid reporting account mappings.');
        foreach ($definitions as $code => $value) {
            Assert::that(is_array($value), 'Invalid reporting account classification.');
            $classification = [];
            foreach ($value as $key => $category) {
                Assert::that(in_array($key, ['profit_or_loss', 'oci', 'equity_component'], true) && is_string($category), 'Invalid reporting account classification.');
                $classification[$key] = $category;
            }
            $accounts[(string) $code] = $classification;
        }
        $components = Row::stringMap($data['equity_components'] ?? []);
        $measures = [];
        $definitions = $data['performance_measures'] ?? [];
        Assert::that(is_array($definitions), 'Invalid performance measure definitions.');
        foreach ($definitions as $key => $value) {
            Assert::that(is_string($key) && is_array($value), 'Invalid performance measure definition.');
            Assert::that(array_diff(array_keys($value), ['label', 'subtotal', 'review_reference', 'adjustments']) === [], 'Unknown performance measure setting.');
            $adjustments = [];
            $entries = $value['adjustments'] ?? null;
            Assert::that(is_array($entries), 'Invalid performance measure adjustments.');
            foreach ($entries as $adjustmentKey => $entry) {
                Assert::that(is_string($adjustmentKey) && is_array($entry), 'Invalid performance measure adjustment.');
                Assert::that(array_diff(array_keys($entry), ['label', 'accounts', 'coefficient']) === [], 'Unknown performance measure adjustment setting.');
                $codes = $entry['accounts'] ?? null;
                Assert::that(is_array($codes) && array_is_list($codes) && $codes !== [], 'Invalid performance measure account selectors.');
                $selected = [];
                foreach ($codes as $code) {
                    Assert::that(is_string($code), 'Performance measure account codes must be strings.');
                    $selected[] = $code;
                }
                $adjustments[$adjustmentKey] = ['label' => Row::text((object) $entry, 'label'), 'accounts' => $selected, 'coefficient' => Row::text((object) $entry, 'coefficient')];
            }
            $measures[$key] = ['label' => Row::text((object) $value, 'label'), 'subtotal' => Row::text((object) $value, 'subtotal'), 'review_reference' => Row::text((object) $value, 'review_reference'), 'adjustments' => $adjustments];
        }

        return new self(Row::text((object) $data, 'name'), Row::text((object) $data, 'version'), Row::text((object) $data, 'main_business_activity'), $accounts, $data['financing_subtotal_required'], $components, $measures);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['name' => $this->name, 'version' => $this->version, 'main_business_activity' => $this->mainBusinessActivity,
            'accounts' => $this->accounts, 'financing_subtotal_required' => $this->financingSubtotalRequired, 'equity_components' => $this->equityComponents, 'performance_measures' => $this->performanceMeasures];
    }
}
