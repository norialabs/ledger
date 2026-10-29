<?php

declare(strict_types=1);

namespace Noria\Ledger;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Reporting\BudgetLine;
use Noria\Ledger\Reporting\BudgetPolicy;
use Noria\Ledger\Reporting\BudgetPosition;
use Noria\Ledger\Reporting\CommitmentEvent;
use Noria\Ledger\Reporting\Snapshot;
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
                $dimensions = Row::stringMap($line->dimensions ?? null);
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

    public function selectPolicy(string $bookId, string $budgetId, string $version, bool $rejectOverBudget, bool $rejectUnbudgeted): string
    {
        Assert::text($version, 128);

        return $this->db->run(Permission::Configure, function () use ($bookId, $budgetId, $version, $rejectOverBudget, $rejectUnbudgeted): string {
            $this->db->lockBook($bookId);
            $this->db->find('ledger_budgets', $budgetId, $bookId);
            $existing = $this->db->table('ledger_budget_policies')->where('book_id', $bookId)->where('version', $version)->first();
            if ($existing instanceof stdClass) {
                Assert::that(Row::text($existing, 'budget_id') === $budgetId && Row::boolean($existing, 'reject_over_budget') === $rejectOverBudget
                    && Row::boolean($existing, 'reject_unbudgeted') === $rejectUnbudgeted, 'Budget policy version already has different inputs.');

                return Row::text($existing, 'id');
            }
            $id = (string) Str::uuid7();
            $this->db->insert('ledger_budget_policies', ['id' => $id, 'book_id' => $bookId, 'budget_id' => $budgetId, 'version' => $version,
                'reject_over_budget' => $rejectOverBudget, 'reject_unbudgeted' => $rejectUnbudgeted]);

            return $id;
        }, $bookId);
    }

    public function commit(string $bookId, string $budgetId, int $lineNo, string $key, string $effectiveOn, Money $amount, string $reference): string
    {
        Assert::that($amount->minor->isPositive(), 'Commitments require a positive amount.');

        return $this->recordEvent($bookId, $key, $effectiveOn, $amount, $reference, 'commitment', $budgetId, $lineNo);
    }

    public function policy(string $bookId, string $policyId): BudgetPolicy
    {
        return $this->db->run(Permission::Read, fn (): BudgetPolicy => BudgetPolicy::fromRow($this->db->find('ledger_budget_policies', $policyId, $bookId)), $bookId);
    }

    public function cancel(string $bookId, string $commitmentId, string $key, string $effectiveOn, Money $amount, string $reason): string
    {
        Assert::that($amount->minor->isPositive(), 'Cancellation requires a positive amount.');

        return $this->recordEvent($bookId, $key, $effectiveOn, $amount->negated(), $reason, 'cancellation', commitmentId: $commitmentId);
    }

    public function reverse(string $bookId, string $eventId, string $key, string $effectiveOn, string $reason): string
    {
        return $this->db->run(Permission::Reverse, function () use ($bookId, $eventId, $key, $effectiveOn, $reason): string {
            $book = $this->db->lockBook($bookId);
            $event = $this->db->find('ledger_commitments', $eventId, $bookId);
            Assert::that(Row::nullable($event, 'journal_id') === null, 'Reverse fulfilment through its journal.');
            $amount = Money::minor(Row::text($event, 'amount_minor'), $this->db->currency(Row::text($book, 'currency_code')))->negated();

            return $this->recordEvent($bookId, $key, $effectiveOn, $amount, $reason, 'reversal', commitmentId: Row::nullable($event, 'commitment_id') ?? $eventId, reversesId: $eventId);
        }, $bookId);
    }

    private function recordEvent(string $bookId, string $key, string $date, Money $amount, string $reason, string $kind,
        ?string $budgetId = null, ?int $lineNo = null, ?string $commitmentId = null, ?string $reversesId = null): string
    {
        Assert::text($key, 128);
        Assert::that(! str_starts_with($key, 'journal:'), 'Commitment keys starting with journal: are reserved.');
        Assert::date($date);
        Assert::text($reason, 1024);

        return $this->db->run($kind === 'reversal' ? Permission::Reverse : Permission::Post, function () use ($bookId, $key, $date, $amount, $reason, $kind, $budgetId, $lineNo, $commitmentId, $reversesId): string {
            $book = $this->db->lockBook($bookId);
            Assert::that($amount->currency == $this->db->currency(Row::text($book, 'currency_code')), 'Commitments use the book currency.');
            if ($commitmentId !== null) {
                $root = $this->db->find('ledger_commitments', $commitmentId, $bookId);
                $budgetId = Row::text($root, 'budget_id');
                $lineNo = (int) Row::text($root, 'line_no');
            }
            $values = ['book_id' => $bookId, 'budget_id' => $budgetId, 'line_no' => $lineNo, 'commitment_id' => $commitmentId,
                'reverses_id' => $reversesId, 'kind' => $kind, 'amount_minor' => (string) $amount->minor, 'effective_on' => $date, 'reason' => $reason];
            $fingerprint = Canonical::hash($values);
            $existing = $this->db->table('ledger_commitments')->where('book_id', $bookId)->where('idempotency_key', $key)->first();
            if ($existing instanceof stdClass) {
                Assert::that(Row::text($existing, 'fingerprint') === $fingerprint, 'Commitment key already has different inputs.');

                return Row::text($existing, 'id');
            }
            $id = (string) Str::uuid7();
            $this->db->insert('ledger_commitments', [...$values, 'id' => $id, 'idempotency_key' => $key, 'fingerprint' => $fingerprint]);

            return $id;
        }, $bookId);
    }

    /** @return list<BudgetPosition> */
    public function position(Snapshot $snapshot, string $budgetId): array
    {
        return $this->db->run(Permission::Read, function () use ($snapshot, $budgetId): array {
            $book = $this->db->snapshotBook($snapshot);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $budget = $this->db->find('ledger_budgets', $budgetId, $snapshot->bookId);
            Assert::that((int) Row::text($budget, 'sequence') <= (int) $snapshot->throughSequence, 'Budget was not known at the snapshot cutoff.');
            $rows = $this->db->connection->select(<<<'SQL'
                SELECT ledger_budget_actual(b.tenant_id, b.book_id, x.l, ?::bigint, ?::date)::text AS actual,
                    (SELECT coalesce(sum(c.amount_minor), 0) FROM ledger_commitments c WHERE c.tenant_id = b.tenant_id AND c.book_id = b.book_id
                        AND c.budget_id = b.id AND c.line_no = x.i AND c.sequence <= ?::bigint AND c.effective_on <= ?::date)::text AS open
                FROM ledger_budgets b CROSS JOIN LATERAL jsonb_array_elements(b.lines) WITH ORDINALITY x(l, i)
                WHERE b.tenant_id = ? AND b.book_id = ? AND b.id = ? ORDER BY x.i
                SQL, [$snapshot->throughSequence, $snapshot->effectiveOn, $snapshot->throughSequence, $snapshot->effectiveOn, $this->db->scope->tenantId, $snapshot->bookId, $budgetId]);
            $result = [];
            foreach ($this->get($snapshot->bookId, $budgetId)['lines'] as $index => $line) {
                $row = $rows[$index] ?? null;
                Assert::that($row instanceof stdClass, 'Budget position is missing.');
                $result[] = new BudgetPosition($index + 1, $line, Money::minor(Row::text($row, 'actual'), $currency), Money::minor(Row::text($row, 'open'), $currency));
            }

            return $result;
        }, $snapshot->bookId);
    }

    /** @return list<CommitmentEvent> */
    public function history(Snapshot $snapshot, string $commitmentId): array
    {
        return $this->db->run(Permission::Read, function () use ($snapshot, $commitmentId): array {
            $book = $this->db->snapshotBook($snapshot);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $root = $this->db->find('ledger_commitments', $commitmentId, $snapshot->bookId);
            Assert::that(Row::text($root, 'kind') === 'commitment', 'History requires a commitment root.');

            return array_values($this->db->table('ledger_commitments')->where('book_id', $snapshot->bookId)
                ->where(fn (Builder $query) => $query->where('id', $commitmentId)->orWhere('commitment_id', $commitmentId))
                ->where('sequence', '<=', $snapshot->throughSequence)->where('effective_on', '<=', $snapshot->effectiveOn)->orderBy('sequence')->get()
                ->map(fn (stdClass $row): CommitmentEvent => CommitmentEvent::fromRow($row, $currency))->all());
        }, $snapshot->bookId);
    }
}
