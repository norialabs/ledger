<?php

declare(strict_types=1);

namespace Noria\Ledger;

use DateTimeImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Accounting\Source;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Canonical;
use Noria\Ledger\Support\Row;
use stdClass;

readonly class YearEnd
{
    public function __construct(private ScopedDatabase $db, private Journals $journals, private Periods $periods) {}

    public function close(string $bookId, string $key, string $startsOn, string $endsOn, string $retainedEarningsAccountId, string $reason): string
    {
        Assert::text($key, 128);
        Assert::text($reason, 1024);
        Assert::date($startsOn);
        Assert::date($endsOn);
        Assert::that($startsOn <= $endsOn, 'Year ends before it starts.');

        return $this->db->run(Permission::Close, function () use ($bookId, $key, $startsOn, $endsOn, $retainedEarningsAccountId, $reason): string {
            $book = $this->db->lockBook($bookId);
            $fingerprint = Canonical::hash([$startsOn, $endsOn, $retainedEarningsAccountId, $reason]);
            $existing = $this->db->table('ledger_year_closures')->where('book_id', $bookId)->where('idempotency_key', $key)->first();

            if ($existing instanceof stdClass) {
                Assert::that(Row::text($existing, 'fingerprint') === $fingerprint, 'Year-end key was already used with different content.');

                return Row::text($existing, 'id');
            }

            $retained = $this->db->find('ledger_accounts', $retainedEarningsAccountId, $bookId);
            Assert::that(Row::text($retained, 'type') === 'equity' && ! Row::boolean($retained, 'is_group') && Row::boolean($retained, 'active'), 'Retained earnings requires an active equity posting account.');
            $periods = $this->db->table('ledger_periods')->where('book_id', $bookId)->where('ends_on', '>=', $startsOn)->where('starts_on', '<=', $endsOn)->orderBy('starts_on')->get();
            $expected = $startsOn;

            foreach ($periods as $period) {
                Assert::that(Row::text($period, 'starts_on') === $expected && Row::text($period, 'ends_on') <= $endsOn, 'Year must be covered by complete, contiguous accounting periods.');
                $expected = (new DateTimeImmutable(Row::text($period, 'ends_on')))->modify('+1 day')->format('Y-m-d');
            }

            Assert::that($expected === (new DateTimeImmutable($endsOn))->modify('+1 day')->format('Y-m-d'), 'Year has missing accounting periods.');
            $query = $this->db->connection->table('ledger_postings AS p')->join('ledger_journals AS j', function (JoinClause $join): void {
                $join->on('j.tenant_id', '=', 'p.tenant_id')->on('j.book_id', '=', 'p.book_id')->on('j.id', '=', 'p.journal_id');
            })->join('ledger_accounts AS a', function (JoinClause $join): void {
                $join->on('a.tenant_id', '=', 'p.tenant_id')->on('a.book_id', '=', 'p.book_id')->on('a.id', '=', 'p.account_id');
            })->where('p.tenant_id', $this->db->scope->tenantId)->where('p.book_id', $bookId)->whereIn('a.type', ['income', 'expense'])->where('j.effective_on', '<=', $endsOn);
            $prior = (clone $query)->where('j.effective_on', '<', $startsOn)->select('p.account_id', 'p.dimensions')
                ->selectRaw("sum(CASE WHEN p.side = 'debit' THEN p.functional_minor ELSE -p.functional_minor END)::text AS balance")
                ->groupBy('p.account_id', 'p.dimensions')->get();

            foreach ($prior as $row) {
                Assert::that($row->balance === '0', 'Unclosed earnings exist before this fiscal year.');
            }

            $balances = $query->where('j.effective_on', '>=', $startsOn)->select('p.account_id', 'p.dimensions')
                ->selectRaw("sum(CASE WHEN p.side = 'debit' THEN p.functional_minor ELSE -p.functional_minor END)::text AS balance")
                ->groupBy('p.account_id', 'p.dimensions')->orderBy('p.account_id')->orderBy('p.dimensions')->get();
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $lines = [];
            $retainedByDimension = [];

            foreach ($balances as $row) {
                $money = Money::minor(Row::text($row, 'balance'), $currency);

                if ($money->minor->isZero()) {
                    continue;
                }

                $dimensions = [];

                foreach (Row::json($row, 'dimensions') as $dimension => $value) {
                    if (is_string($dimension) && is_string($value)) {
                        $dimensions[$dimension] = $value;
                    }
                }

                $dimensionKey = Canonical::json($dimensions);
                $retainedByDimension[$dimensionKey] = ($retainedByDimension[$dimensionKey] ?? Money::minor('0', $currency))->plus($money);
                $lines[] = new Posting(Row::text($row, 'account_id'), $money->minor->isPositive() ? Side::Credit : Side::Debit,
                    Money::minor((string) $money->minor->abs(), $currency), dimensions: $dimensions);
            }

            foreach ($retainedByDimension as $dimensionKey => $money) {
                if ($money->minor->isZero()) {
                    continue;
                }

                $dimensions = json_decode($dimensionKey, true, 512, JSON_THROW_ON_ERROR);
                $lines[] = new Posting($retainedEarningsAccountId, $money->minor->isPositive() ? Side::Debit : Side::Credit,
                    Money::minor((string) $money->minor->abs(), $currency), dimensions: is_array($dimensions) ? $this->stringMap($dimensions) : []);
            }

            $id = (string) Str::uuid7();
            $journal = $lines === [] ? null : $this->journals->post(new JournalRequest($bookId, 'year-end:'.hash('sha256', $key), $endsOn, $reason, $lines,
                ['fiscal_start' => $startsOn, 'fiscal_end' => $endsOn, 'policy' => 'year-end:v1'], closing: true, source: new Source('year_end', $id)));
            $this->db->insert('ledger_year_closures', ['id' => $id, 'book_id' => $bookId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
                'starts_on' => $startsOn, 'ends_on' => $endsOn, 'journal_id' => $journal?->id, 'retained_account_id' => $retainedEarningsAccountId, 'reason' => $reason]);

            foreach ($periods as $period) {
                $this->periods->close($bookId, Row::text($period, 'id'), $reason);
            }

            return $id;
        }, $bookId);
    }

    /** @param non-empty-list<Posting> $postings */
    public function restate(string $bookId, string $key, string $closureId, string $effectiveOn, array $postings, string $retainedEarningsAccountId, string $reason): string
    {
        Assert::text($key, 128);
        Assert::date($effectiveOn);
        Assert::text($reason, 1024);
        Assert::that(count($postings) >= 2, 'A restatement needs at least two as-if postings.');

        return $this->db->run(Permission::Reopen, function () use ($bookId, $key, $closureId, $effectiveOn, $postings, $retainedEarningsAccountId, $reason): string {
            $book = $this->db->lockBook($bookId);
            $fingerprint = Canonical::hash([$closureId, $effectiveOn, $postings, $retainedEarningsAccountId, $reason]);
            $existing = $this->db->table('ledger_restatements')->where('book_id', $bookId)->where('idempotency_key', $key)->first();
            if ($existing instanceof stdClass) {
                Assert::that(Row::text($existing, 'fingerprint') === $fingerprint, 'Restatement key was already used with different content.');

                return Row::text($existing, 'id');
            }
            $closure = $this->db->find('ledger_year_closures', $closureId, $bookId);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $lines = [];
            $attribution = [];
            $retainedByDimension = [];
            foreach ($postings as $posting) {
                $account = $this->db->find('ledger_accounts', $posting->accountId, $bookId);
                if (! in_array(Row::text($account, 'type'), ['income', 'expense'], true)) {
                    $lines[] = $posting;

                    continue;
                }
                Assert::that($posting->amount->currency == $currency && $posting->partyId === null && $posting->valuation === null,
                    'Restated income and expense lines use the book currency without a party.');
                $dimensions = $posting->dimensions;
                ksort($dimensions);
                $attribution[] = ['account_id' => $posting->accountId, 'side' => $posting->side->value, 'functional_minor' => (string) $posting->amount->minor,
                    'dimensions' => (object) $dimensions, 'description' => $posting->description];
                $dimensionKey = Canonical::json($dimensions);
                $signed = $posting->side === Side::Debit ? $posting->amount : $posting->amount->negated();
                $retainedByDimension[$dimensionKey] = ($retainedByDimension[$dimensionKey] ?? Money::minor('0', $currency))->plus($signed);
            }
            Assert::that($attribution !== [], 'A restatement must correct income or expense of the closed year.');
            foreach ($retainedByDimension as $dimensionKey => $money) {
                if (! $money->minor->isZero()) {
                    $dimensions = json_decode($dimensionKey, true, 512, JSON_THROW_ON_ERROR);
                    $lines[] = new Posting($retainedEarningsAccountId, $money->minor->isPositive() ? Side::Debit : Side::Credit,
                        Money::minor((string) $money->minor->abs(), $currency), dimensions: is_array($dimensions) ? $this->stringMap($dimensions) : []);
                }
            }
            $id = (string) Str::uuid7();
            $journal = $this->journals->post(new JournalRequest($bookId, 'restatement:'.hash('sha256', $key), $effectiveOn, $reason, $lines,
                ['restates_closure_id' => $closureId, 'restated_year_end' => Row::text($closure, 'ends_on')], source: new Source('restatement', $id)));
            $this->db->insert('ledger_restatements', ['id' => $id, 'book_id' => $bookId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
                'closure_id' => $closureId, 'journal_id' => $journal->id, 'retained_account_id' => $retainedEarningsAccountId,
                'attribution' => json_encode($attribution, JSON_THROW_ON_ERROR), 'reason' => $reason]);

            return $id;
        }, $bookId);
    }

    /** @param array<array-key, mixed> $values
     * @return array<string, string>
     */
    private function stringMap(array $values): array
    {
        $result = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
