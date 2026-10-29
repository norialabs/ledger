<?php

declare(strict_types=1);

namespace Noria\Ledger;

use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Accounting\Source;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Reporting\ReportingMovement;
use Noria\Ledger\Reporting\Snapshot;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Canonical;
use Noria\Ledger\Support\Row;
use stdClass;

readonly class YearEnd
{
    public const CLOSING_PROCEDURE = 'year-end:v1';

    public function __construct(private ScopedDatabase $db, private Journals $journals, private Periods $periods,
        private ?Reports $reports = null, private ?Schedules $schedules = null, private ?Assets $assets = null) {}

    public function close(string $bookId, string $key, string $startsOn, string $endsOn, string $retainedEarningsAccountId, string $reason, bool $requireChecklist = false): string
    {
        if ($requireChecklist && ! $this->db->table('ledger_year_closures')->where('book_id', Assert::uuid($bookId))->where('idempotency_key', $key)->exists()) {
            $failed = array_column(array_filter($this->checklist($bookId, $startsOn, $endsOn)['checks'], fn (array $check): bool => $check['status'] === 'fail'), 'check');
            Assert::that($failed === [], 'Closing checklist failed: '.implode(', ', $failed).'.');
        }
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

                $dimensions = Row::dimensions($row);
                $dimensionKey = Canonical::json($dimensions);
                $retainedByDimension[$dimensionKey] = ($retainedByDimension[$dimensionKey] ?? Money::minor('0', $currency))->plus($money);
                $lines[] = new Posting(Row::text($row, 'account_id'), $money->minor->isPositive() ? Side::Credit : Side::Debit,
                    Money::minor((string) $money->minor->abs(), $currency), dimensions: $dimensions,
                    reportingMovement: new ReportingMovement('earnings_transfer', self::CLOSING_PROCEDURE, $key));
            }

            foreach ($retainedByDimension as $dimensionKey => $money) {
                if ($money->minor->isZero()) {
                    continue;
                }

                $dimensions = json_decode($dimensionKey, true, 512, JSON_THROW_ON_ERROR);
                $lines[] = new Posting($retainedEarningsAccountId, $money->minor->isPositive() ? Side::Debit : Side::Credit,
                    Money::minor((string) $money->minor->abs(), $currency), dimensions: is_array($dimensions) ? $this->stringMap($dimensions) : [],
                    reportingMovement: new ReportingMovement('earnings_transfer', self::CLOSING_PROCEDURE, $key));
            }

            $id = (string) Str::uuid7();
            $journal = $lines === [] ? null : $this->journals->post(new JournalRequest($bookId, 'year-end:'.hash('sha256', $key), $endsOn, $reason, $lines,
                ['fiscal_start' => $startsOn, 'fiscal_end' => $endsOn, 'policy' => self::CLOSING_PROCEDURE], closing: true, source: new Source('year_end', $id)));
            $this->db->insert('ledger_year_closures', ['id' => $id, 'book_id' => $bookId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
                'starts_on' => $startsOn, 'ends_on' => $endsOn, 'journal_id' => $journal?->id, 'retained_account_id' => $retainedEarningsAccountId, 'reason' => $reason]);

            foreach ($periods as $period) {
                $this->periods->close($bookId, Row::text($period, 'id'), $reason);
            }

            return $id;
        }, $bookId);
    }

    /** @return array{ready: bool, checks: list<array{check: string, status: string, count: int, items: list<string>}>} */
    public function checklist(string $bookId, string $startsOn, string $endsOn): array
    {
        Assert::date($startsOn);
        Assert::date($endsOn);
        Assert::that($startsOn <= $endsOn, 'Checklist range ends before it starts.');
        $reports = $this->reports ?? throw new \LogicException('The closing checklist requires the reports service.');

        return $this->db->run(Permission::Read, function () use ($bookId, $startsOn, $endsOn, $reports): array {
            $book = $this->db->lockBook($bookId);
            $functional = Row::text($book, 'currency_code');
            $snapshot = new Snapshot($bookId, $endsOn, Row::text($book, 'next_sequence'));
            $checks = [];
            $add = function (string $check, string $failure, array $items) use (&$checks): void {
                $items = array_values(array_map(fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $items));
                $checks[] = ['check' => $check, 'status' => $items === [] ? 'pass' : $failure, 'count' => count($items), 'items' => array_slice($items, 0, 100)];
            };

            $gaps = [];
            $expected = $startsOn;
            foreach ($this->db->table('ledger_periods')->where('book_id', $bookId)->where('ends_on', '>=', $startsOn)->where('starts_on', '<=', $endsOn)->orderBy('starts_on')->get() as $period) {
                if (Row::text($period, 'starts_on') > $expected) {
                    $gaps[] = $expected;
                }
                $expected = max($expected, (new DateTimeImmutable(Row::text($period, 'ends_on')))->modify('+1 day')->format('Y-m-d'));
            }
            if ($expected <= $endsOn) {
                $gaps[] = $expected;
            }
            $add('periods_covered', 'fail', $gaps);

            $priorEarnings = [];
            if ($startsOn > '0001-01-01') {
                $prior = new Snapshot($bookId, (new DateTimeImmutable($startsOn))->modify('-1 day')->format('Y-m-d'), Row::text($book, 'next_sequence'));
                foreach ($reports->trialBalance($prior) as $balance) {
                    if (in_array($balance->type, [AccountType::Income, AccountType::Expense], true) && ! $balance->closing->minor->isZero()) {
                        $priorEarnings[] = $balance->code;
                    }
                }
            }
            $add('prior_earnings_closed', 'fail', $priorEarnings);

            $add('drafts_decided', 'fail', $this->db->connection->table('ledger_journal_drafts AS d')->where('d.tenant_id', $this->db->scope->tenantId)->where('d.book_id', $bookId)
                ->where('d.effective_on', '<=', $endsOn)
                ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('ledger_draft_decisions AS x')->whereColumn('x.tenant_id', 'd.tenant_id')->whereColumn('x.book_id', 'd.book_id')->whereColumn('x.draft_id', 'd.id'))
                ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('ledger_journal_drafts AS n')->whereColumn('n.tenant_id', 'd.tenant_id')->whereColumn('n.book_id', 'd.book_id')->whereColumn('n.supersedes_id', 'd.id'))
                ->orderBy('d.sequence')->pluck('d.id')->all());

            $schedules = $this->schedules;
            $add('recurring_posted', 'fail', $schedules === null ? [] : array_map(fn (array $due): string => $due['recurring_id'].':'.$due['occurrence'].':'.$due['kind'], $schedules->dueRecurring($bookId, $endsOn)));
            $add('schedules_posted', 'fail', $schedules === null ? [] : array_map(fn (array $due): string => $due['schedule_id'].':'.$due['line_no'], $schedules->dueSchedules($bookId, $endsOn)));

            $depreciation = [];
            if ($this->assets !== null) {
                foreach ($this->db->table('ledger_assets')->where('book_id', $bookId)->where('effective_on', '<=', $endsOn)->orderBy('reference')->get() as $asset) {
                    $position = $this->assets->position($snapshot, Row::text($asset, 'id'));
                    if (! $position->disposed && $position->depreciationDue->minor->isPositive()) {
                        $depreciation[] = Row::text($asset, 'reference');
                    }
                }
            }
            $add('depreciation_posted', 'fail', $depreciation);

            $add('control_accounts_reconciled', 'fail', $this->db->table('ledger_accounts')->where('book_id', $bookId)->where('is_control', true)->orderBy('code')->get()
                ->reject(fn (stdClass $account): bool => $reports->controlReconciliation($snapshot, Row::text($account, 'id'))->minor->isZero())->pluck('code')->all());
            $add('balance_sheet_balances', 'fail', $reports->balanceSheet($snapshot)['difference']->minor->isZero() ? [] : ['difference']);

            $unrevalued = [];
            foreach ($reports->eachOpenItem($snapshot) as $item) {
                if ($item->outstanding->currency->code !== $functional && ! $this->db->table('ledger_revaluations')->where('book_id', $bookId)->where('item_id', $item->id)
                    ->where('effective_on', $endsOn)->whereNull('reverses_id')->exists()) {
                    $unrevalued[] = $item->id;
                }
            }
            $add('foreign_items_revalued', 'warning', $unrevalued);

            $monetary = $this->db->connection->select(<<<'SQL'
                SELECT a.code FROM ledger_monetary_accounts m JOIN ledger_accounts a ON a.tenant_id = m.tenant_id AND a.book_id = m.book_id AND a.id = m.account_id
                WHERE m.tenant_id = ? AND m.book_id = ? AND EXISTS (SELECT 1 FROM ledger_postings p JOIN ledger_journals j ON j.tenant_id = p.tenant_id AND j.book_id = p.book_id AND j.id = p.journal_id
                    WHERE p.tenant_id = m.tenant_id AND p.book_id = m.book_id AND p.account_id = m.account_id AND j.effective_on <= ?)
                AND NOT EXISTS (SELECT 1 FROM ledger_monetary_valuations v WHERE v.tenant_id = m.tenant_id AND v.book_id = m.book_id AND v.account_id = m.account_id
                    AND v.effective_on = ? AND v.reverses_id IS NULL)
                ORDER BY a.code
                SQL, [$this->db->scope->tenantId, $bookId, $endsOn, $endsOn]);
            $add('monetary_accounts_valued', 'warning', array_map(fn (mixed $row): string => $row instanceof stdClass ? Row::text($row, 'code') : '', $monetary));

            $bank = $this->db->connection->select(<<<'SQL'
                SELECT bl.id FROM ledger_bank_lines bl
                WHERE bl.tenant_id = ? AND bl.book_id = ? AND bl.value_on <= ?
                  AND NOT EXISTS (SELECT 1 FROM ledger_bank_voids v WHERE v.tenant_id = bl.tenant_id AND v.book_id = bl.book_id AND v.statement_id = bl.statement_id)
                  AND abs(bl.amount_minor) > coalesce((SELECT sum(CASE WHEN m.reverses_id IS NULL THEN m.amount_minor ELSE -m.amount_minor END) FROM ledger_bank_matches m
                      WHERE m.tenant_id = bl.tenant_id AND m.book_id = bl.book_id AND m.bank_line_id = bl.id), 0)
                ORDER BY bl.value_on, bl.id
                SQL, [$this->db->scope->tenantId, $bookId, $endsOn]);
            $add('bank_lines_matched', 'warning', array_map(fn (mixed $row): string => $row instanceof stdClass ? Row::text($row, 'id') : '', $bank));

            $statements = $this->db->connection->select(<<<'SQL'
                SELECT a.code FROM ledger_accounts a
                WHERE a.tenant_id = ? AND a.book_id = ? AND a.cash_flow = 'cash' AND a.deleted_at IS NULL
                  AND EXISTS (SELECT 1 FROM ledger_postings p JOIN ledger_journals j ON j.tenant_id = p.tenant_id AND j.book_id = p.book_id AND j.id = p.journal_id
                      WHERE p.tenant_id = a.tenant_id AND p.book_id = a.book_id AND p.account_id = a.id AND j.effective_on <= ?)
                  AND NOT EXISTS (SELECT 1 FROM ledger_bank_statements s WHERE s.tenant_id = a.tenant_id AND s.book_id = a.book_id AND s.account_id = a.id
                      AND s.effective_on >= ? AND s.starts_on <= ?
                      AND NOT EXISTS (SELECT 1 FROM ledger_bank_voids v WHERE v.tenant_id = s.tenant_id AND v.book_id = s.book_id AND v.statement_id = s.id))
                ORDER BY a.code
                SQL, [$this->db->scope->tenantId, $bookId, $endsOn, $endsOn, $endsOn]);
            $add('bank_statements_cover_period_end', 'warning', array_map(fn (mixed $row): string => $row instanceof stdClass ? Row::text($row, 'code') : '', $statements));

            $add('cash_flow_classified', 'warning', $reports->cashFlow($snapshot, $startsOn)->unclassifiedJournalIds);

            $incomeTax = $this->db->connection->select('SELECT item FROM ledger_income_tax_checklist(?::uuid, ?::uuid, ?::date, ?::date)',
                [$this->db->scope->tenantId, $bookId, $startsOn, $endsOn]);
            $add('income_tax_reviewed', 'fail', array_map(fn (mixed $row): string => $row instanceof stdClass ? Row::text($row, 'item') : '', $incomeTax));

            $deferredTax = $this->db->connection->select('SELECT item FROM ledger_deferred_tax_checklist(?::uuid, ?::uuid, ?::date, ?::date)',
                [$this->db->scope->tenantId, $bookId, $startsOn, $endsOn]);
            $add('deferred_tax_remeasured', 'fail', array_map(fn (mixed $row): string => $row instanceof stdClass ? Row::text($row, 'item') : '', $deferredTax));

            $allowances = $this->db->connection->select('SELECT item FROM ledger_deferred_tax_allowance_reconciliation(?::uuid, ?::uuid, ?::date, ?::date)',
                [$this->db->scope->tenantId, $bookId, $startsOn, $endsOn]);
            $add('capital_allowances_reconciled', 'warning', array_map(fn (mixed $row): string => $row instanceof stdClass ? Row::text($row, 'item') : '', $allowances));

            return ['ready' => ! in_array('fail', array_column($checks, 'status'), true), 'checks' => $checks];
        }, $bookId);
    }

    /** @param non-empty-list<Posting> $postings */
    public function restate(string $bookId, string $key, string $closureId, string $effectiveOn, array $postings, string $retainedEarningsAccountId, string $policyVersion, string $reason): string
    {
        Assert::text($key, 128);
        Assert::text($policyVersion, 128);
        Assert::date($effectiveOn);
        Assert::text($reason, 1024);
        Assert::that(count($postings) >= 2, 'A restatement needs at least two as-if postings.');

        return $this->db->run(Permission::Reopen, function () use ($bookId, $key, $closureId, $effectiveOn, $postings, $retainedEarningsAccountId, $policyVersion, $reason): string {
            $book = $this->db->lockBook($bookId);
            $fingerprint = Canonical::hash([$closureId, $effectiveOn, $postings, $retainedEarningsAccountId, $policyVersion, $reason]);
            $restatement = new ReportingMovement('opening_adjustment', $policyVersion, $key);
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
                    $lines[] = Row::text($account, 'type') === 'equity' && $posting->reportingMovement === null ? $posting->withReportingMovement($restatement) : $posting;

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
                        Money::minor((string) $money->minor->abs(), $currency), dimensions: is_array($dimensions) ? $this->stringMap($dimensions) : [],
                        reportingMovement: $restatement);
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

    /** @param array{impracticable: bool, reference: string, explanation: string, earliest_comparative_on: string, application_from: string, current_starts_on: string} $decision
     * @param  array{reference: string, tax_reference: string, postings: list<Posting>, tax: list<Posting>}  $opening
     * @param  list<array{closure_id: string, reference: string, tax_reference: string, postings: list<Posting>, tax: list<Posting>}>  $periods
     */
    public function changePolicy(string $bookId, string $key, string $effectiveOn, string $retainedEarningsAccountId, string $policyVersion,
        array $decision, array $opening, array $periods, string $reason): string
    {
        Assert::text($key, 128);
        Assert::date($effectiveOn);
        Assert::text($policyVersion, 128);
        Assert::text($reason, 1024);

        return $this->db->run(Permission::Reopen, function () use ($bookId, $key, $effectiveOn, $retainedEarningsAccountId, $policyVersion, $decision, $opening, $periods, $reason): string {
            $book = $this->db->lockBook($bookId);
            $fingerprint = Canonical::hash([$effectiveOn, $retainedEarningsAccountId, $policyVersion, $decision, $opening, $periods, $reason]);
            $existing = $this->db->table('ledger_restatements')->where('book_id', $bookId)->where('idempotency_key', $key)->first();
            if ($existing instanceof stdClass) {
                Assert::that(Row::text($existing, 'fingerprint') === $fingerprint, 'Policy-change key was already used with different content.');

                return Row::text($existing, 'id');
            }
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $encodedPeriods = [];
            foreach ($periods as $period) {
                $encodedPeriods[] = $this->policyEffect($period, $currency);
            }
            $inputs = json_encode(['decision' => $decision, 'opening' => $this->policyEffect($opening, $currency), 'periods' => $encodedPeriods], JSON_THROW_ON_ERROR);
            $cutoff = Row::text($book, 'next_sequence');
            $row = $this->db->connection->selectOne('SELECT ledger_policy_change_result(?::uuid, ?::uuid, ?::uuid, ?::date, ?::bigint, ?, ?::jsonb)::text AS result',
                [$this->db->scope->tenantId, $bookId, Assert::uuid($retainedEarningsAccountId), $effectiveOn, $cutoff, $policyVersion, $inputs]);
            Assert::that($row instanceof stdClass, 'Policy-change measurement is missing.');
            $result = Row::jsonObject($row, 'result');
            $movement = new ReportingMovement('opening_adjustment', $policyVersion, $key);
            $lines = Row::objects($result['postings'], function (stdClass $line) use ($currency, $movement, $bookId): Posting {
                $amount = Money::minor(Row::text($line, 'amount_minor'), $currency);
                $account = $this->db->find('ledger_accounts', Row::text($line, 'account_id'), $bookId);

                return new Posting(Row::text($line, 'account_id'), $amount->minor->isPositive() ? Side::Debit : Side::Credit,
                    Money::minor((string) $amount->minor->abs(), $currency), dimensions: Row::stringMap($line->dimensions), reportingMovement: Row::text($account, 'type') === 'equity' ? $movement : null);
            });
            $id = (string) Str::uuid7();
            $journal = $lines === [] ? null : $this->journals->post(new JournalRequest($bookId, 'policy-change:'.hash('sha256', $key), $effectiveOn, $reason, $lines, source: new Source('policy_change', $id)));
            $this->db->insert('ledger_restatements', ['id' => $id, 'book_id' => $bookId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
                'retained_account_id' => $retainedEarningsAccountId, 'attribution' => '[]', 'policy_version' => $policyVersion, 'effective_on' => $effectiveOn,
                'through_sequence' => $cutoff, 'inputs' => $inputs, 'result' => Row::text($row, 'result'), 'journal_id' => $journal?->id, 'reason' => $reason]);

            return $id;
        }, $bookId);
    }

    public function reversePolicyChange(string $bookId, string $policyChangeId, string $key, string $effectiveOn, string $reason): string
    {
        Assert::text($key, 128);
        Assert::date($effectiveOn);
        Assert::text($reason, 1024);

        return $this->db->run(Permission::Reopen, function () use ($bookId, $policyChangeId, $key, $effectiveOn, $reason): string {
            $this->db->lockBook($bookId);
            $fingerprint = Canonical::hash([$policyChangeId, $effectiveOn, $reason]);
            $existing = $this->db->table('ledger_restatements')->where('book_id', $bookId)->where('idempotency_key', $key)->first();
            if ($existing instanceof stdClass) {
                Assert::that(Row::text($existing, 'fingerprint') === $fingerprint, 'Policy-change correction key was already used with different content.');

                return Row::text($existing, 'id');
            }
            $original = $this->db->find('ledger_restatements', $policyChangeId, $bookId);
            Assert::that(Row::nullable($original, 'inputs') !== null && Row::nullable($original, 'reverses_id') === null, 'Only an original policy change can be corrected.');
            $journalId = Row::nullable($original, 'journal_id');
            $journal = $journalId === null ? null : $this->journals->reverse($bookId, $journalId, 'policy-correction:'.hash('sha256', $key), $effectiveOn, $reason);
            $id = (string) Str::uuid7();
            $this->db->insert('ledger_restatements', ['id' => $id, 'book_id' => $bookId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
                'retained_account_id' => Row::text($original, 'retained_account_id'), 'attribution' => '[]', 'policy_version' => Row::text($original, 'policy_version'),
                'effective_on' => $effectiveOn, 'through_sequence' => Row::text($original, 'through_sequence'), 'inputs' => Row::text($original, 'inputs'),
                'result' => Row::text($original, 'result'), 'journal_id' => $journal?->id, 'reverses_id' => $policyChangeId, 'reason' => $reason]);

            return $id;
        }, $bookId);
    }

    /** @return array{id: string, policyVersion: string, effectiveOn: string, sourceCutoff: string, journalId: ?string, reversesId: ?string,
     * comparatives: list<array{on: string, startsOn: string, equityDelta: Money, taxEquityDelta: Money, profitDelta: Money, taxProfitDelta: Money,
     * accounts: list<array{accountId: string, code: string, type: AccountType, original: Money, replacement: Money, originalProfitMovement: Money, replacementProfitMovement: Money}>}>}
     */
    public function policyChangeEvidence(string $bookId, string $policyChangeId): array
    {
        return $this->db->run(Permission::Read, function () use ($bookId, $policyChangeId): array {
            $book = $this->db->find('ledger_books', $bookId);
            $row = $this->db->find('ledger_restatements', $policyChangeId, $bookId);
            Assert::that(Row::nullable($row, 'inputs') !== null, 'This restatement is not a policy change.');
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $result = Row::jsonObject($row, 'result');
            $comparatives = [];
            foreach (Row::objects($result['comparatives'], fn (stdClass $comparison): stdClass => $comparison) as $comparison) {
                $accounts = [];
                foreach (Row::objects($comparison->accounts, fn (stdClass $account): stdClass => $account) as $account) {
                    $accounts[] = $this->policyComparativeAccount($account, $currency);
                }
                $comparatives[] = ['on' => Row::text($comparison, 'on'), 'startsOn' => Row::text($comparison, 'starts_on'),
                    'equityDelta' => Money::minor(Row::text($comparison, 'equity_delta_minor'), $currency), 'taxEquityDelta' => Money::minor(Row::text($comparison, 'tax_equity_delta_minor'), $currency),
                    'profitDelta' => Money::minor(Row::text($comparison, 'profit_delta_minor'), $currency), 'taxProfitDelta' => Money::minor(Row::text($comparison, 'tax_profit_delta_minor'), $currency),
                    'accounts' => $accounts];
            }

            return ['id' => $policyChangeId, 'policyVersion' => Row::text($row, 'policy_version'), 'effectiveOn' => Row::text($row, 'effective_on'),
                'sourceCutoff' => Row::text($row, 'through_sequence'), 'journalId' => Row::nullable($row, 'journal_id'), 'reversesId' => Row::nullable($row, 'reverses_id'),
                'comparatives' => $comparatives];
        }, $bookId);
    }

    /** @param array{closure_id?: string, reference: string, tax_reference: string, postings: list<Posting>, tax: list<Posting>} $effect
     * @return array<string, mixed>
     */
    private function policyEffect(array $effect, Currency $currency): array
    {
        $normalize = function (Posting $posting) use ($currency): array {
            Assert::that($posting->amount->currency == $currency && $posting->exchangeRate === null && $posting->partyId === null
                && $posting->valuation === null && $posting->dueOn === null && $posting->reportingMovement === null,
                'Policy effects use functional amounts without parties, valuations or separate reporting attribution.');

            return ['account_id' => $posting->accountId, 'side' => $posting->side->value, 'functional_minor' => (string) $posting->amount->minor, 'dimensions' => (object) $posting->dimensions];
        };

        return [...$effect, 'postings' => array_map($normalize, $effect['postings']), 'tax' => array_map($normalize, $effect['tax'])];
    }

    /** @return array{accountId: string, code: string, type: AccountType, original: Money, replacement: Money, originalProfitMovement: Money, replacementProfitMovement: Money} */
    private function policyComparativeAccount(stdClass $account, Currency $currency): array
    {
        return ['accountId' => Row::text($account, 'account_id'), 'code' => Row::text($account, 'code'), 'type' => AccountType::from(Row::text($account, 'type')),
            'original' => Money::minor(Row::text($account, 'original_minor'), $currency), 'replacement' => Money::minor(Row::text($account, 'replacement_minor'), $currency),
            'originalProfitMovement' => Money::minor(Row::text($account, 'original_profit_movement_minor'), $currency),
            'replacementProfitMovement' => Money::minor(Row::text($account, 'replacement_profit_movement_minor'), $currency)];
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
