<?php

declare(strict_types=1);

namespace Noria\Ledger\Reporting;

use JsonSerializable;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Canonical;

final readonly class StatementDefinition implements JsonSerializable
{
    /** @param array<string, array{label: string, accounts?: list<string>, terms?: array<string, string>, basis?: string, sign?: string, include_closing?: bool, note?: string}> $rows
     * @param  list<string>  $coverageTypes
     */
    public function __construct(public string $name, public string $version, public array $rows, public array $coverageTypes = [])
    {
        Assert::text($name, 128);
        Assert::text($version, 128);
        Assert::that($rows !== [] && count($rows) <= 1000, 'A statement requires between 1 and 1,000 rows.');
        Canonical::json($rows);

        foreach ($coverageTypes as $type) {
            AccountType::from($type);
        }

        foreach ($rows as $key => $row) {
            Assert::that(is_string($key) && preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]{0,127}$/D', $key) === 1, 'Invalid statement row key.');
            Assert::text($row['label']);
            Assert::that(array_diff(array_keys($row), ['label', 'accounts', 'terms', 'basis', 'sign', 'include_closing', 'note']) === [], 'Unknown statement row setting.');
            Assert::that(isset($row['accounts']) !== isset($row['terms']), 'A statement row requires account selectors or formula terms, exclusively.');
            Assert::that(in_array($row['basis'] ?? 'movement', ['opening', 'movement', 'closing', 'debits', 'credits'], true), 'Invalid statement balance basis.');
            Assert::that(in_array($row['sign'] ?? 'debit', ['debit', 'credit'], true), 'Invalid statement display sign.');

            foreach ($row['accounts'] ?? [] as $code) {
                Assert::text($code, 64);
            }

            foreach ($row['terms'] ?? [] as $reference => $coefficient) {
                Assert::that(isset($rows[$reference]) && preg_match('/^-?\d{1,18}$/D', $coefficient) === 1, 'Statement formulas require existing row references and bounded integer coefficients.');
            }
        }

        $complete = [];
        $visiting = [];
        foreach (array_keys($rows) as $key) {
            $this->visit($key, $visiting, $complete);
        }
    }

    /** @param array<string, bool> $visiting
     * @param  array<string, bool>  $complete
     */
    private function visit(string $key, array &$visiting, array &$complete): void
    {
        if (isset($complete[$key])) {
            return;
        }

        Assert::that(! isset($visiting[$key]), 'Statement formulas cannot contain cycles.');
        $visiting[$key] = true;
        foreach (array_keys($this->rows[$key]['terms'] ?? []) as $reference) {
            $this->visit($reference, $visiting, $complete);
        }
        unset($visiting[$key]);
        $complete[$key] = true;
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        $name = $data['name'] ?? null;
        $version = $data['version'] ?? null;
        $definitions = $data['rows'] ?? null;
        $coverage = $data['coverage_types'] ?? [];
        if (! is_string($name) || ! is_string($version) || ! is_array($definitions) || ! is_array($coverage)) {
            throw new \InvalidArgumentException('Invalid statement definition fields.');
        }
        $order = $data['order'] ?? array_keys($definitions);
        if (! is_array($order) || ! array_is_list($order) || count($order) !== count($definitions)) {
            throw new \InvalidArgumentException('Statement row order must list every row exactly once.');
        }
        $rows = [];
        foreach ($order as $key) {
            if (! is_string($key) || isset($rows[$key]) || ! isset($definitions[$key])) {
                throw new \InvalidArgumentException('Statement row order must list every row exactly once.');
            }
            $value = $definitions[$key];
            if (! is_array($value) || ! is_string($value['label'] ?? null)) {
                throw new \InvalidArgumentException('Invalid statement row.');
            }
            Assert::that(array_diff(array_keys($value), ['label', 'accounts', 'terms', 'basis', 'sign', 'include_closing', 'note']) === [], 'Unknown statement row setting.');
            $row = ['label' => $value['label']];
            if (isset($value['accounts'])) {
                if (! is_array($value['accounts']) || ! array_is_list($value['accounts'])) {
                    throw new \InvalidArgumentException('Statement account selectors must be a list.');
                }
                $row['accounts'] = [];
                foreach ($value['accounts'] as $code) {
                    if (! is_string($code)) {
                        throw new \InvalidArgumentException('Statement account codes must be strings.');
                    }
                    $row['accounts'][] = $code;
                }
            }
            if (isset($value['terms'])) {
                if (! is_array($value['terms'])) {
                    throw new \InvalidArgumentException('Statement formula terms must be a map.');
                }
                $row['terms'] = [];
                foreach ($value['terms'] as $reference => $coefficient) {
                    if (! is_string($reference) || ! is_string($coefficient)) {
                        throw new \InvalidArgumentException('Statement formula references and coefficients must be strings.');
                    }
                    $row['terms'][$reference] = $coefficient;
                }
            }
            foreach (['basis', 'sign', 'note'] as $setting) {
                if (isset($value[$setting])) {
                    if (! is_string($value[$setting])) {
                        throw new \InvalidArgumentException('Statement row setting must be a string.');
                    }
                    $row[$setting] = $value[$setting];
                }
            }
            if (isset($value['include_closing'])) {
                if (! is_bool($value['include_closing'])) {
                    throw new \InvalidArgumentException('Statement closing selector must be a boolean.');
                }
                $row['include_closing'] = $value['include_closing'];
            }
            $rows[$key] = $row;
        }
        $types = [];
        foreach ($coverage as $type) {
            if (! is_string($type)) {
                throw new \InvalidArgumentException('Statement coverage types must be strings.');
            }
            $types[] = $type;
        }

        return new self($name, $version, $rows, $types);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['name' => $this->name, 'version' => $this->version, 'rows' => $this->rows, 'order' => array_keys($this->rows), 'coverage_types' => $this->coverageTypes];
    }
}
