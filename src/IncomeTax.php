<?php

declare(strict_types=1);

namespace Noria\Ledger;

use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Accounting\Source;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Reporting\ReportingMovement;
use Noria\Ledger\Reporting\Snapshot;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Canonical;
use Noria\Ledger\Support\Row;
use Noria\Ledger\Tax\DeferredTaxPosition;
use Noria\Ledger\Tax\DeferredTaxRequest;
use Noria\Ledger\Tax\IncomeTaxComputation;
use Noria\Ledger\Tax\IncomeTaxEvent;
use Noria\Ledger\Tax\IncomeTaxLoss;
use Noria\Ledger\Tax\IncomeTaxReconciliation;
use Noria\Ledger\Tax\IncomeTaxRequest;
use stdClass;

readonly class IncomeTax
{
    public function __construct(private ScopedDatabase $db, private Journals $journals) {}

    public function prepareDeferred(DeferredTaxRequest $request): string
    {
        $snapshot = $request->snapshot;

        return $this->db->run(Permission::Tax, function () use ($request, $snapshot): string {
            $this->db->lockBook($snapshot->bookId);
            $this->db->snapshotBook($snapshot);
            $values = ['book_id' => $snapshot->bookId, 'configuration_id' => $request->configurationId, 'source' => $request->source,
                'effective_on' => $snapshot->effectiveOn, 'through_sequence' => $snapshot->throughSequence, 'inputs' => Canonical::json($request->inputs)];

            return $this->insert('ledger_deferred_tax_computations', $snapshot->bookId, $request->key, Canonical::hash($values), $values);
        }, $snapshot->bookId);
    }

    public function deferred(string $bookId, string $computationId): DeferredTaxPosition
    {
        return $this->db->run(Permission::Read, function () use ($bookId, $computationId): DeferredTaxPosition {
            $book = $this->db->find('ledger_books', $bookId);
            $row = $this->db->find('ledger_deferred_tax_computations', $computationId, $bookId);
            Assert::that(hash_equals(Row::text($row, 'result_hash'), hash('sha256', Row::text($row, 'result'))), 'Deferred tax evidence hash does not match.');

            return DeferredTaxPosition::fromRow($row, $this->db->currency(Row::text($book, 'currency_code')));
        }, $bookId);
    }

    /** @return list<DeferredTaxPosition> */
    public function deferredPositions(Snapshot $snapshot): array
    {
        return $this->db->run(Permission::Read, function () use ($snapshot): array {
            $book = $this->db->snapshotBook($snapshot);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $rows = $this->db->connection->select(<<<'SQL'
                SELECT DISTINCT ON (source) * FROM ledger_deferred_tax_active(?::uuid, ?::uuid, ?::bigint, ?::date) ORDER BY source, sequence DESC
                SQL, [$this->db->scope->tenantId, $snapshot->bookId, $snapshot->throughSequence, $snapshot->effectiveOn]);

            return array_values(array_map(function (mixed $row) use ($currency): DeferredTaxPosition {
                Assert::that($row instanceof stdClass, 'Invalid deferred tax row.');

                return DeferredTaxPosition::fromRow($row, $currency);
            }, $rows));
        }, $snapshot->bookId);
    }

    /** @param array<string, mixed> $evidence */
    public function reviewDeferred(string $bookId, string $computationId, string $key, string $effectiveOn, bool $approved, string $reason, array $evidence): string
    {
        $this->db->authorize(Permission::Approve, $bookId);

        return $this->event($bookId, $computationId, $key, $effectiveOn, $approved ? 'approve' : 'reject', $reason, $evidence, deferred: true);
    }

    public function postDeferred(string $bookId, string $computationId, string $key, string $effectiveOn, string $reason): string
    {
        Assert::text($key, 128);
        Assert::date($effectiveOn);
        Assert::text($reason, 1024);

        return $this->db->run(Permission::Tax, function () use ($bookId, $computationId, $key, $effectiveOn, $reason): string {
            $this->db->authorize(Permission::Post, $bookId);
            $book = $this->db->lockBook($bookId);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $row = $this->db->find('ledger_deferred_tax_computations', $computationId, $bookId);
            $result = (object) Row::json($row, 'result');
            $lines = $result->postings ?? null;
            Assert::that(is_array($lines) && array_is_list($lines), 'Invalid deferred tax postings.');
            $postings = [];
            foreach ($lines as $line) {
                Assert::that(is_array($line), 'Invalid deferred tax posting.');
                $value = (object) $line;
                $amount = Money::minor(Row::text($value, 'minor'), $currency);
                $movement = $line['reporting_movement'] ?? null;
                Assert::that($movement === null || is_array($movement), 'Invalid deferred tax attribution.');
                $postings[] = new Posting(Row::text($value, 'account_id'), $amount->minor->isPositive() ? Side::Debit : Side::Credit,
                    $amount->minor->isPositive() ? $amount : $amount->negated(), reportingMovement: $movement === null ? null : ReportingMovement::fromArray($movement));
            }
            $journalId = null;
            if ($postings !== []) {
                $this->db->connection->select("SELECT set_config('noria.ledger_deferred_tax', ?, true)", [$computationId]);
                try {
                    $journalId = $this->journals->post(new JournalRequest($bookId, 'deferred-tax:'.hash('sha256', $key), $effectiveOn, $reason,
                        $postings, source: new Source('ledger.deferred_tax', $computationId)))->id;
                } finally {
                    $this->db->connection->select("SELECT set_config('noria.ledger_deferred_tax', '', true)");
                }
            }

            return $this->event($bookId, $computationId, $key, $effectiveOn, 'post', $reason, [], journalId: $journalId, deferred: true);
        }, $bookId);
    }

    public function reverseDeferred(string $bookId, string $computationId, string $key, string $effectiveOn, string $reason): string
    {
        Assert::text($key, 128);
        Assert::date($effectiveOn);
        Assert::text($reason, 1024);

        return $this->db->run(Permission::Tax, function () use ($bookId, $computationId, $key, $effectiveOn, $reason): string {
            $this->db->authorize(Permission::Reverse, $bookId);
            $this->db->lockBook($bookId);
            $posted = $this->db->table('ledger_deferred_tax_events')->where('book_id', $bookId)->where('computation_id', $computationId)->where('kind', 'post')->first();
            Assert::that($posted instanceof stdClass, 'Deferred tax computation has not been posted.');
            $journalId = Row::nullable($posted, 'journal_id');
            if ($journalId === null) {
                return $this->event($bookId, $computationId, $key, $effectiveOn, 'reverse', $reason, [], Row::text($posted, 'id'), deferred: true);
            }
            $journal = $this->journals->reverse($bookId, $journalId, 'deferred-tax:'.hash('sha256', $key), $effectiveOn, $reason);

            return Row::text($this->db->table('ledger_deferred_tax_events')->where('book_id', $bookId)->where('journal_id', $journal->id)->firstOrFail(), 'id');
        }, $bookId);
    }

    /** @return list<IncomeTaxEvent> */
    public function deferredHistory(Snapshot $snapshot, string $computationId): array
    {
        return $this->db->run(Permission::Read, function () use ($snapshot, $computationId): array {
            $this->db->snapshotBook($snapshot);
            $this->db->find('ledger_deferred_tax_computations', $computationId, $snapshot->bookId);

            return array_values($this->db->table('ledger_deferred_tax_events')->where('book_id', $snapshot->bookId)->where('computation_id', $computationId)
                ->where('sequence', '<=', $snapshot->throughSequence)->where('effective_on', '<=', $snapshot->effectiveOn)->orderBy('sequence')->get()
                ->map(fn (stdClass $row): IncomeTaxEvent => IncomeTaxEvent::fromRow($row))->all());
        }, $snapshot->bookId);
    }

    public function registerLoss(string $bookId, string $key, string $source, string $vintageOn, Money $amount, string $reference): string
    {
        Assert::text($source, 64);
        Assert::date($vintageOn);
        Assert::text($reference, 256);
        Assert::that($amount->minor->isPositive(), 'An opening tax loss must be positive.');

        return $this->db->run(Permission::Tax, function () use ($bookId, $key, $source, $vintageOn, $amount, $reference): string {
            $book = $this->db->lockBook($bookId);
            Assert::that($amount->currency == $this->db->currency(Row::text($book, 'currency_code')), 'Tax losses use the book currency.');
            $values = ['book_id' => $bookId, 'source' => $source, 'vintage_on' => $vintageOn, 'amount_minor' => (string) $amount->minor, 'reference' => $reference];

            return $this->insert('ledger_income_tax_losses', $bookId, $key, Canonical::hash($values), $values);
        }, $bookId);
    }

    public function prepare(IncomeTaxRequest $request): string
    {
        $snapshot = $request->snapshot;

        return $this->db->run(Permission::Tax, function () use ($request, $snapshot): string {
            $this->db->lockBook($snapshot->bookId);
            $this->db->snapshotBook($snapshot);
            $values = ['book_id' => $snapshot->bookId, 'configuration_id' => $request->configurationId, 'starts_on' => $request->startsOn,
                'ends_on' => $snapshot->effectiveOn, 'through_sequence' => $snapshot->throughSequence, 'source' => $request->source,
                'inputs' => Canonical::json($request->inputs())];

            return $this->insert('ledger_income_tax_computations', $snapshot->bookId, $request->key, Canonical::hash($values), $values);
        }, $snapshot->bookId);
    }

    public function reverseLoss(string $bookId, string $lossId, string $key, string $effectiveOn, string $reference): string
    {
        Assert::date($effectiveOn);
        Assert::text($reference, 256);

        return $this->db->run(Permission::Tax, function () use ($bookId, $lossId, $key, $effectiveOn, $reference): string {
            $this->db->authorize(Permission::Reverse, $bookId);
            $book = $this->db->lockBook($bookId);
            $loss = $this->db->find('ledger_income_tax_losses', $lossId, $bookId);
            $amount = Money::minor(Row::text($loss, 'amount_minor'), $this->db->currency(Row::text($book, 'currency_code')))->negated();
            $values = ['book_id' => $bookId, 'source' => Row::text($loss, 'source'), 'vintage_on' => $effectiveOn, 'amount_minor' => (string) $amount->minor,
                'reference' => $reference, 'reverses_id' => $lossId];

            return $this->insert('ledger_income_tax_losses', $bookId, $key, Canonical::hash($values), $values);
        }, $bookId);
    }

    /** @param array<string, mixed> $evidence */
    public function review(string $bookId, string $computationId, string $key, string $effectiveOn, bool $approved, string $reason, array $evidence): string
    {
        $this->db->authorize(Permission::Approve, $bookId);

        return $this->event($bookId, $computationId, $key, $effectiveOn, $approved ? 'approve' : 'reject', $reason, $evidence);
    }

    public function post(string $bookId, string $computationId, string $key, string $effectiveOn, string $reason): string
    {
        Assert::text($key, 128);
        Assert::date($effectiveOn);
        Assert::text($reason, 1024);

        return $this->db->run(Permission::Tax, function () use ($bookId, $computationId, $key, $effectiveOn, $reason): string {
            $this->db->authorize(Permission::Post, $bookId);
            $book = $this->db->lockBook($bookId);
            $row = $this->db->find('ledger_income_tax_computations', $computationId, $bookId);
            $amount = Money::minor(Row::text((object) Row::json($row, 'result'), 'charge_minor'), $this->db->currency(Row::text($book, 'currency_code')));
            $journalId = $amount->minor->isZero() ? null : $this->chargeJournal($bookId, $computationId, $key, $effectiveOn, $reason, $amount,
                Row::text($row, 'expense_account_id'), Row::text($row, 'payable_account_id'));

            return $this->event($bookId, $computationId, $key, $effectiveOn, 'post', $reason, [], journalId: $journalId);
        }, $bookId);
    }

    public function reverse(string $bookId, string $computationId, string $key, string $effectiveOn, string $reason): string
    {
        return $this->db->run(Permission::Tax, function () use ($bookId, $computationId, $key, $effectiveOn, $reason): string {
            $this->db->authorize(Permission::Reverse, $bookId);
            $this->db->lockBook($bookId);
            $posted = $this->db->table('ledger_income_tax_events')->where('book_id', $bookId)->where('computation_id', $computationId)->where('kind', 'post')->first();
            Assert::that($posted instanceof stdClass, 'Income tax computation has not been posted.');
            $journalId = Row::nullable($posted, 'journal_id');
            if ($journalId === null) {
                return $this->event($bookId, $computationId, $key, $effectiveOn, 'reverse', $reason, [], Row::text($posted, 'id'));
            }
            $journal = $this->journals->reverse($bookId, $journalId, 'income-tax:'.hash('sha256', $key), $effectiveOn, $reason);

            return Row::text($this->db->table('ledger_income_tax_events')->where('book_id', $bookId)->where('journal_id', $journal->id)->firstOrFail(), 'id');
        }, $bookId);
    }

    public function get(string $bookId, string $computationId): IncomeTaxComputation
    {
        return $this->db->run(Permission::Read, function () use ($bookId, $computationId): IncomeTaxComputation {
            $book = $this->db->find('ledger_books', $bookId);
            $row = $this->db->find('ledger_income_tax_computations', $computationId, $bookId);
            Assert::that(hash_equals(Row::text($row, 'result_hash'), hash('sha256', Row::text($row, 'result'))), 'Income tax evidence hash does not match.');

            return IncomeTaxComputation::fromRow($row, $this->db->currency(Row::text($book, 'currency_code')));
        }, $bookId);
    }

    /** @return list<IncomeTaxEvent> */
    public function history(Snapshot $snapshot, string $computationId): array
    {
        return $this->db->run(Permission::Read, function () use ($snapshot, $computationId): array {
            $this->db->snapshotBook($snapshot);
            $this->db->find('ledger_income_tax_computations', $computationId, $snapshot->bookId);

            return array_values($this->db->table('ledger_income_tax_events')->where('book_id', $snapshot->bookId)->where('computation_id', $computationId)
                ->where('sequence', '<=', $snapshot->throughSequence)->where('effective_on', '<=', $snapshot->effectiveOn)->orderBy('sequence')->get()
                ->map(fn (stdClass $row): IncomeTaxEvent => IncomeTaxEvent::fromRow($row))->all());
        }, $snapshot->bookId);
    }

    /** @return list<IncomeTaxLoss> */
    public function losses(Snapshot $snapshot, bool $includeCorrections = false): array
    {
        return $this->db->run(Permission::Read, function () use ($snapshot, $includeCorrections): array {
            $book = $this->db->snapshotBook($snapshot);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $rows = $this->db->connection->select(<<<'SQL'
                WITH active AS MATERIALIZED (SELECT * FROM ledger_income_tax_active(?::uuid, ?::uuid, ?::bigint, ?::date)),
                    standing AS MATERIALIZED (SELECT id FROM ledger_income_tax_active(?::uuid, ?::uuid, ?::bigint, '9999-12-31')),
                    used AS (SELECT x.key, sum(x.value::numeric) AS used FROM active a CROSS JOIN LATERAL jsonb_each_text(a.inputs->'losses') x GROUP BY x.key)
                SELECT l.*, coalesce(u.used, 0)::text AS used_minor,
                    (CASE WHEN l.reverses_id IS NULL
                        AND (l.computation_id IS NULL OR EXISTS (SELECT 1 FROM active WHERE id = l.computation_id) AND EXISTS (SELECT 1 FROM standing WHERE id = l.computation_id))
                        AND NOT EXISTS (SELECT 1 FROM ledger_income_tax_losses r WHERE r.tenant_id = l.tenant_id AND r.book_id = l.book_id AND r.reverses_id = l.id AND r.sequence <= ?)
                        THEN l.amount_minor - coalesce(u.used, 0) ELSE 0 END)::text AS remaining_minor
                FROM ledger_income_tax_losses l LEFT JOIN used u ON u.key = l.id::text
                WHERE l.tenant_id = ? AND l.book_id = ? AND l.sequence <= ? AND l.vintage_on <= ? AND (l.reverses_id IS NULL OR ?::boolean) ORDER BY l.sequence, l.id
                SQL, [$this->db->scope->tenantId, $snapshot->bookId, $snapshot->throughSequence, $snapshot->effectiveOn,
                $this->db->scope->tenantId, $snapshot->bookId, $snapshot->throughSequence, $snapshot->throughSequence,
                $this->db->scope->tenantId, $snapshot->bookId, $snapshot->throughSequence, $snapshot->effectiveOn, $includeCorrections]);

            $result = [];
            foreach ($rows as $row) {
                Assert::that($row instanceof stdClass, 'Invalid income tax loss row.');
                $result[] = IncomeTaxLoss::fromRow($row, $currency);
            }

            return $result;
        }, $snapshot->bookId);
    }

    public function reconciliation(Snapshot $snapshot, string $computationId): IncomeTaxReconciliation
    {
        return $this->db->run(Permission::Read, function () use ($snapshot, $computationId): IncomeTaxReconciliation {
            $book = $this->db->snapshotBook($snapshot);
            $row = $this->db->find('ledger_income_tax_computations', $computationId, $snapshot->bookId);
            Assert::that((int) Row::text($row, 'sequence') <= (int) $snapshot->throughSequence, 'Computation was not known at the cutoff.');
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $zero = Money::minor('0', $currency);
            $events = $this->history($snapshot, $computationId);
            $status = $events === [] ? 'prepared' : $events[array_key_last($events)]->kind;
            $posted = in_array($status, ['post', 'reverse'], true);
            $credits = $posted ? Money::minor(Row::text((object) Row::json($row, 'result'), 'credits_minor'), $currency) : $zero;
            $charge = $status === 'post' ? Money::minor(Row::text((object) Row::json($row, 'result'), 'charge_minor'), $currency) : $zero;
            $totals = $this->db->connection->selectOne(<<<'SQL'
                SELECT coalesce(sum(CASE WHEN p.side = 'credit' THEN p.functional_minor ELSE -p.functional_minor END), 0)::text AS minor
                FROM ledger_income_tax_events e JOIN ledger_postings p ON p.tenant_id = e.tenant_id AND p.book_id = e.book_id AND p.journal_id = e.journal_id
                WHERE e.tenant_id = ? AND e.book_id = ? AND e.computation_id = ? AND e.sequence <= ? AND e.effective_on <= ? AND p.account_id = ?
                SQL, [$this->db->scope->tenantId, $snapshot->bookId, $computationId, $snapshot->throughSequence, $snapshot->effectiveOn, Row::text($row, 'payable_account_id')]);
            Assert::that($totals instanceof stdClass, 'Missing tax reconciliation.');
            $payments = $this->db->connection->selectOne(<<<'SQL'
                SELECT coalesce(sum((v->>'amount_minor')::numeric), 0)::text AS minor
                FROM ledger_income_tax_computations c, jsonb_array_elements(c.inputs->'credits') v
                WHERE c.tenant_id = ? AND c.book_id = ? AND c.id = ? AND NOT EXISTS (
                    SELECT 1 FROM ledger_journals r WHERE r.tenant_id = c.tenant_id AND r.book_id = c.book_id AND r.reverses_id = (v->>'journal_id')::uuid
                        AND r.sequence <= ? AND r.effective_on <= ?)
                SQL, [$this->db->scope->tenantId, $snapshot->bookId, $computationId, $snapshot->throughSequence, $snapshot->effectiveOn]);
            Assert::that($payments instanceof stdClass, 'Missing tax payment reconciliation.');
            $bookedCredits = $posted ? Money::minor(Row::text($payments, 'minor'), $currency) : $zero;

            return new IncomeTaxReconciliation($computationId, $status, $charge, $credits, Money::minor(Row::text($totals, 'minor'), $currency), $bookedCredits);
        }, $snapshot->bookId);
    }

    private function chargeJournal(string $bookId, string $computationId, string $key, string $effectiveOn, string $reason, Money $amount, string $expense, string $payable): string
    {
        $this->db->connection->select("SELECT set_config('noria.ledger_income_tax', ?, true)", [$computationId]);
        try {
            return $this->journals->post(new JournalRequest($bookId, 'income-tax:'.hash('sha256', $key), $effectiveOn, $reason, [
                new Posting($expense, Side::Debit, $amount), new Posting($payable, Side::Credit, $amount),
            ], source: new Source('ledger.income_tax', $computationId)))->id;
        } finally {
            $this->db->connection->select("SELECT set_config('noria.ledger_income_tax', '', true)");
        }
    }

    /** @param array<string, mixed> $evidence */
    private function event(string $bookId, string $computationId, string $key, string $date, string $kind, string $reason, array $evidence, ?string $reversesId = null, ?string $journalId = null, bool $deferred = false): string
    {
        Assert::date($date);
        Assert::text($reason, 1024);

        return $this->db->run(Permission::Tax, function () use ($bookId, $computationId, $key, $date, $kind, $reason, $evidence, $reversesId, $journalId, $deferred): string {
            $this->db->lockBook($bookId);
            $this->db->find($deferred ? 'ledger_deferred_tax_computations' : 'ledger_income_tax_computations', $computationId, $bookId);
            $values = ['book_id' => $bookId, 'computation_id' => $computationId, 'effective_on' => $date, 'kind' => $kind, 'reason' => $reason,
                'evidence' => $evidence === [] ? '{}' : Canonical::json($evidence), 'reverses_id' => $reversesId, 'journal_id' => $journalId];

            return $this->insert($deferred ? 'ledger_deferred_tax_events' : 'ledger_income_tax_events', $bookId, $key, Canonical::hash($values), $values);
        }, $bookId);
    }

    /** @param array<string, mixed> $values */
    private function insert(string $table, string $bookId, string $key, string $fingerprint, array $values): string
    {
        Assert::text($key, 128);
        Assert::that(! str_starts_with($key, 'journal:') && ! str_starts_with($key, 'computation:'), 'Income tax key prefix is reserved.');
        $existing = $this->db->table($table)->where('book_id', $bookId)->where('idempotency_key', $key)->first();
        if ($existing instanceof stdClass) {
            Assert::that(hash_equals(Row::text($existing, 'fingerprint'), $fingerprint), 'Income tax key already has different inputs.');

            return Row::text($existing, 'id');
        }
        $id = (string) Str::uuid7();
        $this->db->insert($table, [...$values, 'id' => $id, 'idempotency_key' => $key, 'fingerprint' => $fingerprint]);

        return $id;
    }
}
