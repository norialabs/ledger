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
     */
    public function __construct(
        public StatementDefinition $definition,
        public array $columns,
        public Currency $currency,
        public array $rowOrder,
        public array $rows,
        public ?array $configuration = null,
    ) {}

    /** @return array{definition: StatementDefinition, columns: non-empty-list<StatementColumn>, currency: string, row_order: list<string>, rows: array<string, array{label: string, note: ?string, values: array<string, Money>}>, configuration?: array{id: string, content_hash: string, definition_hash: string}} */
    public function jsonSerialize(): array
    {
        return ['definition' => $this->definition, 'columns' => $this->columns, 'currency' => $this->currency->code, 'row_order' => $this->rowOrder, 'rows' => $this->rows,
            ...($this->configuration === null ? [] : ['configuration' => $this->configuration])];
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

        return new self($definition, $columns, $currency, array_keys($order), $rows, $configuration);
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
