<?php

declare(strict_types=1);

namespace Noria\Ledger\Reporting;

use JsonSerializable;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Row;
use stdClass;

final readonly class Statement implements JsonSerializable
{
    /** @param non-empty-list<StatementColumn> $columns
     * @param  list<string>  $rowOrder
     * @param  array<string, array{label: string, note: ?string, values: array<string, Money>}>  $rows
     * @param  ?array{id: string, content_hash: string, definition_hash: string}  $configuration
     * @param  array<string, array<string, array<string, array{tax: Money, nci: Money}>>>  $performanceMeasureEffects
     */
    public function __construct(
        public StatementDefinition $definition,
        public array $columns,
        public Currency $currency,
        public array $rowOrder,
        public array $rows,
        public ?array $configuration = null,
        public array $performanceMeasureEffects = [],
    ) {}

    /** @return array{definition: StatementDefinition, columns: non-empty-list<StatementColumn>, currency: string, row_order: list<string>, rows: array<string, array{label: string, note: ?string, values: array<string, Money>}>, configuration?: array{id: string, content_hash: string, definition_hash: string}, performance_measure_effects?: array<string, array<string, array<string, array{tax: Money, nci: Money}>>>} */
    public function jsonSerialize(): array
    {
        return ['definition' => $this->definition, 'columns' => $this->columns, 'currency' => $this->currency->code, 'row_order' => $this->rowOrder, 'rows' => $this->rows,
            ...($this->configuration === null ? [] : ['configuration' => $this->configuration]),
            ...($this->performanceMeasureEffects === [] ? [] : ['performance_measure_effects' => $this->performanceMeasureEffects])];
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        $definitionData = $data['definition'] ?? null;
        $columnData = $data['columns'] ?? null;
        $rowOrder = $data['row_order'] ?? null;
        $rowData = $data['rows'] ?? null;
        if (! is_array($definitionData) || ! is_array($columnData) || ! array_is_list($columnData) || $columnData === []
            || ! is_array($rowOrder) || ! array_is_list($rowOrder) || ! is_array($rowData)) {
            throw new LedgerException('Invalid saved statement structure.');
        }
        $definition = StatementDefinition::fromArray($definitionData);
        $columns = [];
        $columnKeys = [];
        foreach ($columnData as $value) {
            $column = self::object($value);
            $snapshot = self::object($column->snapshot ?? null);
            $key = Row::text($column, 'key');
            Assert::that(! isset($columnKeys[$key]), 'Saved statement repeats a column.');
            $columns[] = new StatementColumn($key, Row::text($column, 'label'),
                new Snapshot(Row::text($snapshot, 'book_id'), Row::text($snapshot, 'effective_on'), Row::text($snapshot, 'through_sequence')),
                Row::text($column, 'starts_on'), self::dimensions($column->dimensions ?? null));
            Assert::that($columns[0]->snapshot->bookId === $columns[array_key_last($columns)]->snapshot->bookId, 'Saved statement columns cross books.');
            $columnKeys[$key] = true;
        }
        $order = [];
        foreach ($rowOrder as $key) {
            if (! is_string($key) || isset($order[$key]) || ! isset($definition->rows[$key])) {
                throw new LedgerException('Invalid saved statement row order.');
            }
            $order[$key] = true;
        }
        Assert::that(array_keys($order) === array_keys($definition->rows) && count($rowData) === count($order), 'Saved statement rows differ from their definition.');
        $currencyCode = Row::text((object) $data, 'currency');
        $currency = null;
        $rows = [];
        foreach (array_keys($order) as $key) {
            $row = self::object($rowData[$key] ?? null);
            if (! is_array($row->values ?? null) || count($row->values) !== count($columnKeys)) {
                throw new LedgerException('Invalid saved statement row values.');
            }
            $values = [];
            foreach (array_keys($columnKeys) as $columnKey) {
                $value = self::object($row->values[$columnKey] ?? null);
                if (! is_int($value->scale ?? null)) {
                    throw new LedgerException('Invalid saved statement currency scale.');
                }
                $valueCurrency = new Currency(Row::text($value, 'currency'), $value->scale);
                Assert::that($valueCurrency->code === $currencyCode && ($currency === null || $currency == $valueCurrency), 'Saved statement currencies differ.');
                $currency = $valueCurrency;
                $values[$columnKey] = Money::of(Row::text($value, 'amount'), $currency);
            }
            $rows[$key] = ['label' => Row::text($row, 'label'), 'note' => Row::nullable($row, 'note'), 'values' => $values];
        }
        Assert::that($currency !== null, 'Saved statement has no monetary values.');
        $configuration = null;
        if (isset($data['configuration'])) {
            $pinned = self::object($data['configuration']);
            $configuration = ['id' => Row::text($pinned, 'id'), 'content_hash' => Row::text($pinned, 'content_hash'), 'definition_hash' => Row::text($pinned, 'definition_hash')];
        }

        $effects = [];
        $storedEffects = $data['performance_measure_effects'] ?? [];
        Assert::that(is_array($storedEffects), 'Invalid saved performance measure effects.');
        foreach ($storedEffects as $columnKey => $measures) {
            Assert::that(is_string($columnKey) && is_array($measures), 'Invalid saved performance measure effects.');
            $effects[$columnKey] = [];
            foreach ($measures as $key => $adjustments) {
                Assert::that(is_string($key) && is_array($adjustments), 'Invalid saved performance measure effects.');
                $effects[$columnKey][$key] = [];
                foreach ($adjustments as $adjustmentKey => $value) {
                    Assert::that(is_string($adjustmentKey), 'Invalid saved performance measure adjustment.');
                    $effect = self::object($value);
                    Assert::that(array_diff(array_keys((array) $effect), ['tax', 'nci']) === [], 'Unknown performance measure effect.');
                    $amounts = [];
                    foreach (['tax', 'nci'] as $field) {
                        $amount = self::object($effect->{$field} ?? null);
                        Assert::that(($amount->currency ?? null) === $currency->code && ($amount->scale ?? null) === $currency->scale, 'Performance measure effect currency differs from the statement.');
                        $amounts[$field] = Money::of(Row::text($amount, 'amount'), $currency);
                    }
                    $effects[$columnKey][$key][$adjustmentKey] = ['tax' => $amounts['tax'], 'nci' => $amounts['nci']];
                }
            }
        }
        self::validatePerformanceMeasureEffects($definition, $columns, $currency, $effects);

        return new self($definition, $columns, $currency, array_keys($order), $rows, $configuration, $effects);
    }

    /** @param non-empty-list<StatementColumn> $columns
     * @param  array<string, array<string, array<string, array{tax: Money, nci: Money}>>>  $effects
     */
    public static function validatePerformanceMeasureEffects(StatementDefinition $definition, array $columns, Currency $currency, array $effects): void
    {
        $measures = $definition->reportingMapping->performanceMeasures ?? [];
        if ($measures === []) {
            Assert::that($effects === [], 'Performance measure effects require a reporting measure definition.');

            return;
        }
        Assert::that(count($effects) === count($columns), 'Every statement column requires explicit performance measure effects.');
        foreach ($columns as $column) {
            $columnEffects = $effects[$column->key] ?? [];
            Assert::that(count($columnEffects) === count($measures), 'Every performance measure requires explicit tax and NCI effects.');
            foreach ($measures as $key => $measure) {
                $adjustments = $columnEffects[$key] ?? [];
                Assert::that(count($adjustments) === count($measure['adjustments']), 'Every performance measure adjustment requires explicit tax and NCI effects.');
                foreach ($measure['adjustments'] as $adjustmentKey => $adjustment) {
                    $effect = $adjustments[$adjustmentKey] ?? null;
                    Assert::that($effect !== null && array_diff(array_keys($effect), ['tax', 'nci']) === [] && isset($effect['tax'], $effect['nci']), 'Every performance measure adjustment requires explicit tax and NCI effects.');
                    Assert::that($effect['tax']->currency == $currency && $effect['nci']->currency == $currency, 'Performance measure effect currency differs from the statement.');
                }
            }
        }
    }

    private static function object(mixed $value): stdClass
    {
        if (! is_array($value)) {
            throw new LedgerException('Invalid saved statement object.');
        }

        return (object) $value;
    }

    /** @return array<string, string> */
    private static function dimensions(mixed $value): array
    {
        if (! is_array($value)) {
            throw new LedgerException('Invalid saved statement dimensions.');
        }
        $result = [];
        foreach ($value as $key => $dimension) {
            if (! is_string($key) || ! is_string($dimension)) {
                throw new LedgerException('Invalid saved statement dimension.');
            }
            $result[$key] = $dimension;
        }

        return $result;
    }
}
