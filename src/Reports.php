<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger;

use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use NoriaLabs\Ledger\Access\Permission;
use NoriaLabs\Ledger\Accounting\AccountType;
use NoriaLabs\Ledger\Accounting\Side;
use NoriaLabs\Ledger\Money\Currency;
use NoriaLabs\Ledger\Money\Money;
use NoriaLabs\Ledger\Reporting\AccountBalance;
use NoriaLabs\Ledger\Reporting\CashFlow;
use NoriaLabs\Ledger\Reporting\OpenItem;
use NoriaLabs\Ledger\Reporting\Snapshot;
use NoriaLabs\Ledger\Storage\ScopedDatabase;
use NoriaLabs\Ledger\Support\Assert;
use NoriaLabs\Ledger\Support\Canonical;
use NoriaLabs\Ledger\Support\Row;
use stdClass;

final readonly class Reports
{
    public function __construct(private ScopedDatabase $db) {}

    public function snapshot(string $bookId, string $effectiveOn): Snapshot
    {
        Assert::date($effectiveOn);

        return $this->db->run(Permission::Read, function () use ($bookId, $effectiveOn): Snapshot {
            $book = $this->db->lockBook($bookId);

            return new Snapshot($bookId, $effectiveOn, Row::text($book, 'next_sequence'));
        }, $bookId);
    }

    /** @param non-empty-list<string> $bookIds
     * @return array<string, Snapshot>
     */
    public function snapshots(array $bookIds, string $effectiveOn): array
    {
        Assert::date($effectiveOn);
        Assert::that($bookIds !== [], 'At least one book is required.');
        sort($bookIds, SORT_STRING);

        return $this->db->run(Permission::Read, function () use ($bookIds, $effectiveOn): array {
            $snapshots = [];

            foreach (array_unique($bookIds) as $bookId) {
                $this->db->authorize(Permission::Read, $bookId);
                $book = $this->db->lockBook($bookId);
                $snapshots[$bookId] = new Snapshot($bookId, $effectiveOn, Row::text($book, 'next_sequence'));
            }

            return $snapshots;
        }, $bookIds[0]);
    }

    /** @param array<string, string> $dimensions
     * @return list<AccountBalance>
     */
    public function trialBalance(Snapshot $snapshot, string $startsOn = '0001-01-01', array $dimensions = [], bool $includeClosing = true): array
    {
        Assert::date($startsOn);
        Assert::that($startsOn <= $snapshot->effectiveOn, 'Report starts after it ends.');

        return $this->db->run(Permission::Read, function () use ($snapshot, $startsOn, $dimensions, $includeClosing): array {
            $book = $this->db->find('ledger_books', $snapshot->bookId);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $query = $this->postings($snapshot, $dimensions)->select('p.account_id')
                ->selectRaw("coalesce(sum(CASE WHEN j.effective_on < ? THEN CASE WHEN p.side = 'debit' THEN p.functional_minor ELSE -p.functional_minor END ELSE 0 END), 0)::text AS opening", [$startsOn])
                ->selectRaw("coalesce(sum(CASE WHEN j.effective_on >= ? AND p.side = 'debit' THEN p.functional_minor ELSE 0 END), 0)::text AS debits", [$startsOn])
                ->selectRaw("coalesce(sum(CASE WHEN j.effective_on >= ? AND p.side = 'credit' THEN p.functional_minor ELSE 0 END), 0)::text AS credits", [$startsOn]);

            if (! $includeClosing) {
                $query->where('j.closing', false);
            }

            $totals = $query->groupBy('p.account_id')->get()->keyBy('account_id');
            $result = [];

            foreach ($this->db->table('ledger_accounts')->where('book_id', $snapshot->bookId)->where('is_group', false)->orderBy('code')->get() as $account) {
                $sum = $totals->get(Row::text($account, 'id'));
                $opening = Money::minor($sum instanceof stdClass ? Row::text($sum, 'opening') : '0', $currency);
                $debits = Money::minor($sum instanceof stdClass ? Row::text($sum, 'debits') : '0', $currency);
                $credits = Money::minor($sum instanceof stdClass ? Row::text($sum, 'credits') : '0', $currency);
                $result[] = new AccountBalance(Row::text($account, 'id'), Row::text($account, 'code'), Row::text($account, 'name'), AccountType::from(Row::text($account, 'type')),
                    $opening, $debits, $credits, $opening->plus($debits)->minus($credits));
            }

            return $result;
        }, $snapshot->bookId);
    }

    /** @param array<string, string> $dimensions
     * @return array{income: Money, expenses: Money, profit: Money}
     */
    public function incomeStatement(Snapshot $snapshot, string $startsOn, array $dimensions = []): array
    {
        $balances = $this->trialBalance($snapshot, $startsOn, $dimensions, false);
        $currency = $this->functionalCurrency($snapshot->bookId);
        $income = Money::minor('0', $currency);
        $expenses = Money::minor('0', $currency);

        foreach ($balances as $balance) {
            $movement = $balance->debits->minus($balance->credits);

            if ($balance->type === AccountType::Income) {
                $income = $income->minus($movement);
            } elseif ($balance->type === AccountType::Expense) {
                $expenses = $expenses->plus($movement);
            }
        }

        return ['income' => $income, 'expenses' => $expenses, 'profit' => $income->minus($expenses)];
    }

    /** @param array<string, string> $dimensions
     * @return array{assets: Money, liabilities: Money, equity: Money, unclosed_earnings: Money, difference: Money}
     */
    public function balanceSheet(Snapshot $snapshot, array $dimensions = []): array
    {
        $balances = $this->trialBalance($snapshot, dimensions: $dimensions);
        $currency = $this->functionalCurrency($snapshot->bookId);
        $totals = array_fill_keys(['asset', 'liability', 'equity', 'income', 'expense'], Money::minor('0', $currency));

        foreach ($balances as $balance) {
            $totals[$balance->type->value] = $totals[$balance->type->value]->plus($balance->closing);
        }

        $liabilities = $totals['liability']->negated();
        $equity = $totals['equity']->negated();
        $earnings = $totals['income']->plus($totals['expense'])->negated();

        return ['assets' => $totals['asset'], 'liabilities' => $liabilities, 'equity' => $equity,
            'unclosed_earnings' => $earnings, 'difference' => $totals['asset']->minus($liabilities)->minus($equity)->minus($earnings)];
    }

    public function cashFlow(Snapshot $snapshot, string $startsOn): CashFlow
    {
        Assert::date($startsOn);
        Assert::that($startsOn <= $snapshot->effectiveOn, 'Cash-flow report starts after it ends.');

        return $this->db->run(Permission::Read, function () use ($snapshot, $startsOn): CashFlow {
            $book = $this->db->find('ledger_books', $snapshot->bookId);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $query = $this->postings($snapshot)->join('ledger_accounts AS a', function (JoinClause $join): void {
                $join->on('a.tenant_id', '=', 'p.tenant_id')->on('a.book_id', '=', 'p.book_id')->on('a.id', '=', 'p.account_id');
            });
            $totals = (clone $query)->where('a.cash_flow', 'cash')
                ->selectRaw("coalesce(sum(CASE WHEN j.effective_on < ? THEN CASE WHEN p.side = 'debit' THEN p.functional_minor ELSE -p.functional_minor END ELSE 0 END), 0)::text AS opening", [$startsOn])
                ->selectRaw("coalesce(sum(CASE WHEN p.side = 'debit' THEN p.functional_minor ELSE -p.functional_minor END), 0)::text AS closing")->first();
            $opening = Money::minor($totals instanceof stdClass ? Row::text($totals, 'opening') : '0', $currency);
            $closing = Money::minor($totals instanceof stdClass ? Row::text($totals, 'closing') : '0', $currency);
            $journals = $query->where('j.effective_on', '>=', $startsOn)->select('p.journal_id', 'j.metadata')
                ->selectRaw("coalesce(sum(CASE WHEN a.cash_flow = 'cash' THEN CASE WHEN p.side = 'debit' THEN p.functional_minor ELSE -p.functional_minor END ELSE 0 END), 0)::text AS cash_delta")
                ->selectRaw("count(*) AS line_count, count(*) FILTER (WHERE a.cash_flow = 'cash') AS cash_count, bool_and(p.valuation) FILTER (WHERE a.cash_flow = 'cash') AS valuations")
                ->selectRaw("max(a.cash_flow) FILTER (WHERE a.cash_flow <> 'cash') AS counter_category")
                ->groupBy('p.journal_id', 'j.metadata')->havingRaw("count(*) FILTER (WHERE a.cash_flow = 'cash') > 0")->get();
            $buckets = array_fill_keys(['operating', 'investing', 'financing', 'exchange', 'unclassified'], Money::minor('0', $currency));
            $unclassifiedIds = [];

            foreach ($journals as $journal) {
                $delta = Money::minor(Row::text($journal, 'cash_delta'), $currency);
                $metadata = Row::json($journal, 'metadata');
                $allocation = $metadata['cash_flow'] ?? null;

                if (is_array($allocation)) {
                    foreach ($allocation as $category => $minor) {
                        Assert::that(is_string($category) && isset($buckets[$category]) && $category !== 'unclassified' && is_string($minor), 'Invalid cash-flow allocation.');

                        if (is_string($category) && isset($buckets[$category]) && is_string($minor)) {
                            $buckets[$category] = $buckets[$category]->plus(Money::minor($minor, $currency));
                        }
                    }

                    continue;
                }

                if (Row::boolean($journal, 'valuations')) {
                    $category = 'exchange';
                } elseif (Row::text($journal, 'line_count') === Row::text($journal, 'cash_count')) {
                    Assert::that($delta->minor->isZero(), 'An internal cash transfer must have zero net functional effect.');

                    continue;
                } elseif (Row::text($journal, 'line_count') === '2' && Row::text($journal, 'cash_count') === '1'
                    && in_array(Row::nullable($journal, 'counter_category'), ['operating', 'investing', 'financing'], true)) {
                    $category = Row::text($journal, 'counter_category');
                } else {
                    $category = 'unclassified';
                    $unclassifiedIds[] = Row::text($journal, 'journal_id');
                }

                $buckets[$category] = $buckets[$category]->plus($delta);
            }

            return new CashFlow($opening, $closing, $buckets['operating'], $buckets['investing'], $buckets['financing'], $buckets['exchange'], $buckets['unclassified'], $unclassifiedIds);
        }, $snapshot->bookId);
    }

    /** @return list<OpenItem> */
    public function openItems(Snapshot $snapshot, ?string $partyId = null, bool $includeSettled = false): array
    {
        return $this->db->run(Permission::Read, function () use ($snapshot, $partyId, $includeSettled): array {
            $book = $this->db->find('ledger_books', $snapshot->bookId);
            $functional = $this->db->currency(Row::text($book, 'currency_code'));
            $allocationEvents = function (Side $side) use ($snapshot): Builder {
                $itemColumn = $side === Side::Debit ? 'debit_item_id' : 'credit_item_id';
                $functionalColumn = $side === Side::Debit ? 'debit_functional_minor' : 'credit_functional_minor';

                return $this->db->table('ledger_allocations')->where('book_id', $snapshot->bookId)
                    ->where('effective_on', '<=', $snapshot->effectiveOn)->where('sequence', '<=', $snapshot->throughSequence)
                    ->selectRaw($itemColumn.' AS item_id')
                    ->selectRaw('CASE WHEN reverses_id IS NULL THEN amount_minor ELSE -amount_minor END AS amount')
                    ->selectRaw('CASE WHEN reverses_id IS NULL THEN '.$functionalColumn.' ELSE -'.$functionalColumn.' END AS functional');
            };
            $events = $allocationEvents(Side::Debit)->unionAll($allocationEvents(Side::Credit));
            $totals = $this->db->connection->query()->fromSub($events, 'events')->select('item_id')
                ->selectRaw('sum(amount)::text AS amount, sum(functional)::text AS functional')->groupBy('item_id');
            $revaluations = $this->db->table('ledger_revaluations')->where('book_id', $snapshot->bookId)->where('effective_on', '<=', $snapshot->effectiveOn)
                ->where('sequence', '<=', $snapshot->throughSequence)->select('item_id')->selectRaw('sum(delta_minor)::text AS delta')->groupBy('item_id');
            $query = $this->postings($snapshot)->join('ledger_open_items AS oi', function (JoinClause $join): void {
                $join->on('oi.tenant_id', '=', 'p.tenant_id')->on('oi.book_id', '=', 'p.book_id')->on('oi.journal_id', '=', 'p.journal_id')->on('oi.line_no', '=', 'p.line_no');
            })->leftJoinSub($totals, 'allocated', fn (JoinClause $join) => $join->on('allocated.item_id', '=', 'oi.id'))
                ->leftJoinSub($revaluations, 'revalued', fn (JoinClause $join) => $join->on('revalued.item_id', '=', 'oi.id'))
                ->select('oi.id', 'p.*', 'j.effective_on')->selectRaw("coalesce(allocated.amount, '0') AS allocated_minor, coalesce(allocated.functional, '0') AS allocated_functional")
                ->selectRaw("coalesce(revalued.delta, '0') AS revaluation_minor")
                ->orderBy('j.effective_on')->orderBy('j.sequence')->orderBy('p.line_no');

            if ($partyId !== null) {
                $query->where('p.party_id', Assert::uuid($partyId));
            }

            $currencies = [];

            foreach ($this->db->table('ledger_currencies')->get() as $registered) {
                $currencies[Row::text($registered, 'code')] = new Currency(Row::text($registered, 'code'), (int) Row::text($registered, 'scale'));
            }

            $result = [];

            foreach ($query->get() as $row) {
                $side = Side::from(Row::text($row, 'side'));
                $currency = $currencies[Row::text($row, 'currency_code')];
                $original = Money::minor(Row::text($row, 'amount_minor'), $currency);
                $remaining = $original->minus(Money::minor(Row::text($row, 'allocated_minor'), $currency));

                if ($includeSettled || ! $remaining->minor->isZero()) {
                    $result[] = new OpenItem(Row::text($row, 'id'), Row::text($row, 'journal_id'), (int) Row::text($row, 'line_no'), Row::text($row, 'account_id'), Row::text($row, 'party_id'),
                        $side, Row::text($row, 'effective_on'), Row::nullable($row, 'due_on') ?? Row::text($row, 'effective_on'), $original, $remaining,
                        Money::minor(Row::text($row, 'functional_minor'), $functional)->plus(Money::minor(Row::text($row, 'revaluation_minor'), $functional))->minus(Money::minor(Row::text($row, 'allocated_functional'), $functional)));
                }
            }

            return $result;
        }, $snapshot->bookId);
    }

    /** @return array<string, array<string, Money>> */
    public function aging(Snapshot $snapshot, ?string $partyId = null): array
    {
        $result = [];
        $asOf = new DateTimeImmutable($snapshot->effectiveOn);

        foreach ($this->openItems($snapshot, $partyId) as $item) {
            $days = (int) (new DateTimeImmutable($item->dueOn))->diff($asOf)->format('%r%a');
            $bucket = match (true) {
                $days <= 0 => 'current', $days <= 30 => '1-30', $days <= 60 => '31-60', $days <= 90 => '61-90', default => '91+',
            };
            $key = $item->outstanding->currency->code;
            $signed = $item->side === Side::Debit ? $item->outstanding : $item->outstanding->negated();
            $result[$key][$bucket] = ($result[$key][$bucket] ?? Money::minor('0', $signed->currency))->plus($signed);
        }

        return $result;
    }

    public function controlReconciliation(Snapshot $snapshot, string $accountId): Money
    {
        return $this->db->run(Permission::Read, function () use ($snapshot, $accountId): Money {
            $account = $this->db->find('ledger_accounts', $accountId, $snapshot->bookId);
            Assert::that(Row::boolean($account, 'is_control'), 'Subledger reconciliation requires a control account.');
            $book = $this->db->find('ledger_books', $snapshot->bookId);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $total = $this->postings($snapshot)->where('p.account_id', $accountId)
                ->selectRaw("coalesce(sum(CASE WHEN p.side = 'debit' THEN p.functional_minor ELSE -p.functional_minor END), 0)::text AS balance")->first();
            $balance = Money::minor($total instanceof stdClass ? Row::text($total, 'balance') : '0', $currency);
            $subledger = Money::minor('0', $currency);

            foreach ($this->openItems($snapshot) as $item) {
                if ($item->accountId === $accountId) {
                    $subledger = $subledger->plus($item->side === Side::Debit ? $item->functionalOutstanding : $item->functionalOutstanding->negated());
                }
            }

            return $balance->minus($subledger);
        }, $snapshot->bookId);
    }

    /** @param array<string, string> $dimensions
     * @return list<stdClass>
     */
    public function generalLedger(Snapshot $snapshot, ?string $accountId = null, int $limit = 500, string $afterSequence = '0', int $afterLine = 0, array $dimensions = []): array
    {
        Assert::that($limit >= 1 && $limit <= 10000 && $afterLine >= 0, 'Invalid ledger page size or cursor.');

        return $this->db->run(Permission::Read, function () use ($snapshot, $accountId, $limit, $afterSequence, $afterLine, $dimensions): array {
            $this->db->find('ledger_books', $snapshot->bookId);
            $query = $this->postings($snapshot, $dimensions)->select('p.*', 'j.effective_on', 'j.recorded_at', 'j.sequence', 'j.description AS journal_description', 'j.metadata', 'j.actor_id', 'j.firm_id')
                ->whereRaw('(j.sequence, p.line_no) > (?::bigint, ?::integer)', [$afterSequence, $afterLine])->orderBy('j.sequence')->orderBy('p.line_no')->limit($limit);

            if ($accountId !== null) {
                $query->where('p.account_id', Assert::uuid($accountId));
            }

            return array_values($query->get()->all());
        }, $snapshot->bookId);
    }

    private function functionalCurrency(string $bookId): Currency
    {
        return $this->db->run(Permission::Read, function () use ($bookId): Currency {
            $book = $this->db->find('ledger_books', $bookId);

            return $this->db->currency(Row::text($book, 'currency_code'));
        }, $bookId);
    }

    /** @param array<string, string> $dimensions */
    private function postings(Snapshot $snapshot, array $dimensions = []): Builder
    {
        $query = $this->db->connection->table('ledger_postings AS p')->join('ledger_journals AS j', function (JoinClause $join): void {
            $join->on('j.tenant_id', '=', 'p.tenant_id')->on('j.book_id', '=', 'p.book_id')->on('j.id', '=', 'p.journal_id');
        })->where('p.tenant_id', $this->db->scope->tenantId)->where('p.book_id', $snapshot->bookId)->where('j.effective_on', '<=', $snapshot->effectiveOn)->where('j.sequence', '<=', $snapshot->throughSequence);

        if ($dimensions !== []) {
            $query->whereRaw('p.dimensions @> ?::jsonb', [Canonical::json($dimensions)]);
        }

        return $query;
    }
}
