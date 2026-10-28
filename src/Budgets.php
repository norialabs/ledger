<?php

declare(strict_types=1);

namespace Noria\Ledger;

use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Reporting\BudgetLine;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Canonical;
use Noria\Ledger\Support\Row;
use stdClass;

readonly class Budgets
{
    public function __construct(private ScopedDatabase $db) {}

    /** @param non-empty-list<BudgetLine> $lines */
    public function create(string $bookId, string $name, string $version, array $lines): string
    {
        Assert::text($name, 128);
        Assert::text($version, 128);
        Assert::that($lines !== [] && count($lines) <= 100000, 'A budget requires between 1 and 100,000 lines.');

        return $this->db->run(Permission::Configure, function () use ($bookId, $name, $version, $lines): string {
            $book = $this->db->lockBook($bookId);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            foreach ($lines as $line) {
                Assert::that($line->amount->currency == $currency, 'Budget amounts use the book currency.');
            }
            usort($lines, fn (BudgetLine $a, BudgetLine $b): int => [$a->accountId, Canonical::json($a->dimensions), $a->startsOn] <=> [$b->accountId, Canonical::json($b->dimensions), $b->startsOn]);
            $fingerprint = Canonical::hash($lines);
            $json = json_encode(array_map(fn (BudgetLine $line): array => [...$line->jsonSerialize(), 'dimensions' => (object) $line->jsonSerialize()['dimensions']], $lines), JSON_THROW_ON_ERROR);
            $existing = $this->db->table('ledger_budgets')->where('book_id', $bookId)->where('name', $name)->where('version', $version)->first();
            if ($existing instanceof stdClass) {
                Assert::that(Row::text($existing, 'fingerprint') === $fingerprint, 'Budget version already exists with different lines; create a new version.');

                return Row::text($existing, 'id');
            }
            $id = (string) Str::uuid7();
            $this->db->insert('ledger_budgets', ['id' => $id, 'book_id' => $bookId, 'name' => $name, 'version' => $version, 'fingerprint' => $fingerprint, 'lines' => $json]);

            return $id;
        }, $bookId);
    }

    /** @return array{id: string, name: string, version: string, recorded_at: string, lines: list<BudgetLine>} */
    public function get(string $bookId, string $budgetId): array
    {
        return $this->db->run(Permission::Read, function () use ($bookId, $budgetId): array {
            $book = $this->db->find('ledger_books', $bookId);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $row = $this->db->find('ledger_budgets', $budgetId, $bookId);
            $lines = [];
            foreach (Row::json($row, 'lines') as $line) {
                Assert::that(is_array($line), 'Invalid stored budget.');
                $line = (object) $line;
                $dimensions = [];
                foreach ((array) ($line->dimensions ?? []) as $key => $value) {
                    if (is_string($key) && is_string($value)) {
                        $dimensions[$key] = $value;
                    }
                }
                $lines[] = new BudgetLine(Row::text($line, 'account_id'), Row::text($line, 'starts_on'), Row::text($line, 'ends_on'),
                    Money::minor(Row::text($line, 'amount_minor'), $currency), $dimensions);
            }

            return ['id' => $budgetId, 'name' => Row::text($row, 'name'), 'version' => Row::text($row, 'version'), 'recorded_at' => Row::text($row, 'recorded_at'), 'lines' => $lines];
        }, $bookId);
    }

    /** @return list<array{id: string, name: string, version: string, recorded_at: string}> */
    public function list(string $bookId): array
    {
        return $this->db->run(Permission::Read, function () use ($bookId): array {
            $this->db->find('ledger_books', $bookId);

            return array_values($this->db->table('ledger_budgets')->where('book_id', $bookId)->orderBy('name')->orderBy('sequence')->get()
                ->map(fn (stdClass $row): array => ['id' => Row::text($row, 'id'), 'name' => Row::text($row, 'name'), 'version' => Row::text($row, 'version'), 'recorded_at' => Row::text($row, 'recorded_at')])->all());
        }, $bookId);
    }
}
