<?php

declare(strict_types=1);

namespace Noria\Ledger;

use Brick\Math\BigInteger;
use Brick\Math\BigRational;
use Generator;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\Journal;
use Noria\Ledger\Accounting\JournalLine;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Money\Rounding;
use Noria\Ledger\Reporting\AccountBalance;
use Noria\Ledger\Reporting\CashFlow;
use Noria\Ledger\Reporting\LedgerEntry;
use Noria\Ledger\Reporting\OpenItem;
use Noria\Ledger\Reporting\PartyBalance;
use Noria\Ledger\Reporting\PartyStatement;
use Noria\Ledger\Reporting\Snapshot;
use Noria\Ledger\Reporting\Statement;
use Noria\Ledger\Reporting\StatementColumn;
use Noria\Ledger\Reporting\StatementDefinition;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Canonical;
use Noria\Ledger\Support\Row;
use stdClass;

readonly class Reports
{
    public const AGING_BUCKETS = ['current', '1-30', '31-60', '61-90', '91+'];

    private const OUTSTANDING = 'p.amount_minor - coalesce(allocated.amount, 0)';

    private const PAGE = 1000;

    public function __construct(private ScopedDatabase $db, private ?Configurations $configurations = null) {}

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
    public function trialBalance(Snapshot $snapshot, string $startsOn = '0001-01-01', array $dimensions = [], bool $includeClosing = true, bool $restated = false): array
    {
        Assert::date($startsOn);
        Assert::that($startsOn <= $snapshot->effectiveOn, 'Report starts after it ends.');

        return $this->db->run(Permission::Read, function () use ($snapshot, $startsOn, $dimensions, $includeClosing, $restated): array {
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
            $adjustments = $restated ? $this->restatementAdjustments($snapshot, $startsOn, $dimensions, $includeClosing) : [];
            $result = [];

            foreach ($this->db->table('ledger_accounts')->where('book_id', $snapshot->bookId)->where('is_group', false)->orderBy('code')->get() as $account) {
                $sum = $totals->get(Row::text($account, 'id'));
                $adjustment = $adjustments[Row::text($account, 'id')] ?? ['opening' => BigInteger::zero(), 'debits' => BigInteger::zero(), 'credits' => BigInteger::zero()];
                $opening = Money::minor((string) BigInteger::of($sum instanceof stdClass ? Row::text($sum, 'opening') : '0')->plus($adjustment['opening']), $currency);
                $debits = Money::minor((string) BigInteger::of($sum instanceof stdClass ? Row::text($sum, 'debits') : '0')->plus($adjustment['debits']), $currency);
                $credits = Money::minor((string) BigInteger::of($sum instanceof stdClass ? Row::text($sum, 'credits') : '0')->plus($adjustment['credits']), $currency);
                $result[] = new AccountBalance(Row::text($account, 'id'), Row::text($account, 'code'), Row::text($account, 'name'), AccountType::from(Row::text($account, 'type')),
                    $opening, $debits, $credits, $opening->plus($debits)->minus($credits));
            }

            return $result;
        }, $snapshot->bookId);
    }

    /** @param array<string, string> $dimensions
     * @return array{income: Money, expenses: Money, profit: Money}
     */
    public function incomeStatement(Snapshot $snapshot, string $startsOn, array $dimensions = [], bool $restated = false): array
    {
        $balances = $this->trialBalance($snapshot, $startsOn, $dimensions, false, $restated);
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
    public function balanceSheet(Snapshot $snapshot, array $dimensions = [], bool $restated = false): array
    {
        $balances = $this->trialBalance($snapshot, dimensions: $dimensions, restated: $restated);
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

    /** @param array<string, string> $dimensions
     * @return array{income: Money, expenses: Money, profit: Money, accounts: array<string, Money>}
     */
    public function cashBasisIncomeStatement(Snapshot $snapshot, string $startsOn, array $dimensions = []): array
    {
        Assert::date($startsOn);
        Assert::that($startsOn <= $snapshot->effectiveOn, 'Report starts after it ends.');

        return $this->db->run(Permission::Read, function () use ($snapshot, $startsOn, $dimensions): array {
            $this->db->snapshotBook($snapshot);
            $book = $this->db->find('ledger_books', $snapshot->bookId);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $tenant = $this->db->scope->tenantId;
            $bindings = [$tenant, $snapshot->bookId, $snapshot->effectiveOn, $snapshot->throughSequence];
            $lines = $this->db->connection->select(<<<'SQL'
                SELECT p.journal_id, j.effective_on, p.account_id, a.type, a.cash_flow, a.is_control, p.side, p.functional_minor::text AS functional_minor,
                    p.dimensions, p.valuation, oi.id AS item_id,
                    EXISTS (SELECT 1 FROM ledger_allocations x WHERE x.tenant_id = j.tenant_id AND x.book_id = j.book_id AND x.fx_journal_id = j.id) AS realized_fx
                FROM ledger_postings p
                JOIN ledger_journals j ON j.tenant_id = p.tenant_id AND j.book_id = p.book_id AND j.id = p.journal_id
                JOIN ledger_accounts a ON a.tenant_id = p.tenant_id AND a.book_id = p.book_id AND a.id = p.account_id
                LEFT JOIN ledger_open_items oi ON oi.tenant_id = p.tenant_id AND oi.book_id = p.book_id AND oi.journal_id = p.journal_id AND oi.line_no = p.line_no
                WHERE p.tenant_id = ? AND p.book_id = ? AND j.effective_on <= ? AND j.sequence <= ? AND NOT j.closing
                  AND j.id IN (SELECT q.journal_id FROM ledger_postings q JOIN ledger_accounts b ON b.tenant_id = q.tenant_id AND b.book_id = q.book_id AND b.id = q.account_id
                      WHERE q.tenant_id = p.tenant_id AND q.book_id = p.book_id AND b.type IN ('income', 'expense'))
                ORDER BY p.journal_id, p.line_no
                SQL, $bindings);
            $allocations = $this->db->connection->select(<<<'SQL'
                SELECT item_id, effective_on, CASE WHEN reverses_id IS NULL THEN functional ELSE -functional END::text AS functional FROM (
                    SELECT debit_item_id AS item_id, effective_on, reverses_id, debit_functional_minor AS functional, sequence, tenant_id, book_id FROM ledger_allocations
                    UNION ALL SELECT credit_item_id, effective_on, reverses_id, credit_functional_minor, sequence, tenant_id, book_id FROM ledger_allocations) x
                WHERE tenant_id = ? AND book_id = ? AND effective_on <= ? AND sequence <= ?
                SQL, $bindings);
            $settlements = [];
            foreach (array_filter($allocations, fn (mixed $row): bool => $row instanceof stdClass) as $allocation) {
                $settlements[Row::text($allocation, 'item_id')][] = [Row::text($allocation, 'effective_on'), BigInteger::of(Row::text($allocation, 'functional'))];
            }
            $journals = [];
            foreach (array_filter($lines, fn (mixed $row): bool => $row instanceof stdClass) as $line) {
                $journals[Row::text($line, 'journal_id')][] = $line;
            }
            $accounts = [];
            foreach ($journals as $journalLines) {
                $postedOn = Row::text($journalLines[0], 'effective_on');
                $cash = BigInteger::zero();
                $control = BigInteger::zero();
                $events = [];
                $earnings = [];
                foreach ($journalLines as $line) {
                    $signed = BigInteger::of(Row::text($line, 'functional_minor'))->multipliedBy(Row::text($line, 'side') === 'debit' ? 1 : -1);
                    if (in_array(Row::text($line, 'type'), ['income', 'expense'], true)) {
                        if (array_diff_assoc($dimensions, Row::dimensions($line)) === []) {
                            $earnings[] = [Row::text($line, 'account_id'), $signed];
                        }
                    } elseif (Row::nullable($line, 'cash_flow') === 'cash') {
                        $cash = $cash->plus($signed);
                    } elseif (Row::nullable($line, 'item_id') !== null) {
                        $control = $control->plus(BigInteger::of(Row::text($line, 'functional_minor')));
                        foreach ($settlements[Row::text($line, 'item_id')] ?? [] as $settlement) {
                            $events[] = $settlement;
                        }
                    }
                }
                if (Row::boolean($journalLines[0], 'realized_fx')) {
                    $base = BigInteger::one();
                    $events = [[$postedOn, BigInteger::one()]];
                } else {
                    $base = $cash->abs()->plus($control);
                    $events[] = [$postedOn, $cash->abs()];
                }
                if ($base->isZero() || $earnings === []) {
                    continue;
                }
                $before = BigInteger::zero();
                $through = BigInteger::zero();
                foreach ($events as [$date, $amount]) {
                    $through = $through->plus($amount);
                    if ($date < $startsOn) {
                        $before = $before->plus($amount);
                    }
                }
                foreach ($earnings as [$accountId, $signed]) {
                    $recognized = BigRational::of($signed)->multipliedBy($through)->dividedBy($base)->toScale(0, Rounding::HalfEven->brick())->toBigInteger()
                        ->minus(BigRational::of($signed)->multipliedBy($before)->dividedBy($base)->toScale(0, Rounding::HalfEven->brick())->toBigInteger());
                    $accounts[$accountId] = ($accounts[$accountId] ?? BigInteger::zero())->plus($recognized);
                }
            }
            $income = Money::minor('0', $currency);
            $expenses = Money::minor('0', $currency);
            $result = [];
            foreach ($this->db->table('ledger_accounts')->where('book_id', $snapshot->bookId)->whereIn('type', ['income', 'expense'])->orderBy('code')->get() as $account) {
                $id = Row::text($account, 'id');
                if (! isset($accounts[$id])) {
                    continue;
                }
                $amount = Money::minor((string) $accounts[$id], $currency);
                $result[$id] = $amount;
                if (Row::text($account, 'type') === 'income') {
                    $income = $income->minus($amount);
                } else {
                    $expenses = $expenses->plus($amount);
                }
            }

            return ['income' => $income, 'expenses' => $expenses, 'profit' => $income->minus($expenses), 'accounts' => $result];
        }, $snapshot->bookId);
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

                        $buckets[$category] = $buckets[$category]->plus(Money::minor($minor, $currency));
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
    public function openItems(Snapshot $snapshot, ?string $partyId = null, bool $includeSettled = false, ?string $accountId = null, int $limit = 1000, ?string $after = null): array
    {
        Assert::that($limit >= 1 && $limit <= 10000, 'Invalid open-item page size.');
        $position = $after === null ? null : self::position($after);

        return $this->db->run(Permission::Read, function () use ($snapshot, $partyId, $includeSettled, $accountId, $limit, $position): array {
            $query = $this->filteredOpenItems($snapshot, true, $partyId, $accountId);
            if (! $includeSettled) {
                $query->whereRaw(self::OUTSTANDING.' <> 0');
            }
            if ($position !== null) {
                $query->whereRaw('(oi.effective_on, oi.sequence, oi.line_no) > (?::date, ?::bigint, ?::integer)', $position);
            }

            return $this->openItemList($snapshot, $query->orderBy('oi.effective_on')->orderBy('oi.sequence')->orderBy('oi.line_no')->limit($limit)->get());
        }, $snapshot->bookId);
    }

    /** @return Generator<int, OpenItem> */
    public function eachOpenItem(Snapshot $snapshot, ?string $partyId = null, ?string $accountId = null): Generator
    {
        $after = null;
        do {
            $page = $this->openItems($snapshot, $partyId, accountId: $accountId, limit: self::PAGE, after: $after);
            foreach ($page as $item) {
                yield $item;
                $after = $item->cursor();
            }
        } while (count($page) === self::PAGE);
    }

    public function openItem(Snapshot $snapshot, string $itemId): ?OpenItem
    {
        return $this->db->run(Permission::Read, fn (): ?OpenItem => $this->openItemList($snapshot,
            $this->filteredOpenItems($snapshot, true)->where('oi.id', Assert::uuid($itemId))->get())[0] ?? null, $snapshot->bookId);
    }

    /** @param array<string, string> $dimensions
     * @return array<string, array<string, Money>>
     */
    public function aging(Snapshot $snapshot, ?string $partyId = null, ?string $accountId = null, array $dimensions = []): array
    {
        return $this->db->run(Permission::Read, function () use ($snapshot, $partyId, $accountId, $dimensions): array {
            $rows = $this->agedItems($snapshot, $partyId, $accountId, $dimensions)->addSelect('items.currency_code')
                ->selectRaw("sum(CASE WHEN items.side = 'debit' THEN items.outstanding_minor ELSE -items.outstanding_minor END)::text AS total")
                ->groupBy('items.currency_code')->orderBy('items.currency_code')->get();
            $result = [];
            foreach ($rows as $row) {
                $code = Row::text($row, 'currency_code');
                $result[$code][self::AGING_BUCKETS[(int) Row::text($row, 'bucket')]] = Money::minor(Row::text($row, 'total'), $this->db->currency($code));
            }

            return $result;
        }, $snapshot->bookId);
    }

    /** @return array<string, Money> */
    public function exposure(Snapshot $snapshot, string $accountId): array
    {
        return $this->db->run(Permission::Read, function () use ($snapshot, $accountId): array {
            $functional = $this->functionalCurrency($snapshot->bookId);
            $exposure = array_fill_keys(self::AGING_BUCKETS, Money::minor('0', $functional));
            $rows = $this->agedItems($snapshot, null, $accountId)->where('items.side', Side::Debit->value)
                ->selectRaw('sum(items.functional_outstanding_minor)::text AS total')->get();
            foreach ($rows as $row) {
                $exposure[self::AGING_BUCKETS[(int) Row::text($row, 'bucket')]] = Money::minor(Row::text($row, 'total'), $functional);
            }

            return $exposure;
        }, $snapshot->bookId);
    }

    public function controlReconciliation(Snapshot $snapshot, string $accountId): Money
    {
        return $this->db->run(Permission::Read, function () use ($snapshot, $accountId): Money {
            $account = $this->db->find('ledger_accounts', $accountId, $snapshot->bookId);
            Assert::that(Row::boolean($account, 'is_control'), 'Subledger reconciliation requires a control account.');
            $currency = $this->functionalCurrency($snapshot->bookId);
            $signed = "coalesce(sum(CASE WHEN %s = 'debit' THEN %s ELSE -%s END), 0)::text AS balance";
            $balance = $this->postings($snapshot)->where('p.account_id', $accountId)->selectRaw(sprintf($signed, 'p.side', 'p.functional_minor', 'p.functional_minor'))->value('balance');
            $items = $this->filteredOpenItems($snapshot, false, accountId: $accountId)->whereRaw(self::OUTSTANDING.' <> 0');
            $subledger = $this->db->connection->query()->fromSub($items, 'items')
                ->selectRaw(sprintf($signed, 'items.side', 'items.functional_outstanding_minor', 'items.functional_outstanding_minor'))->value('balance');

            return Money::minor(is_string($balance) ? $balance : '0', $currency)->minus(Money::minor(is_string($subledger) ? $subledger : '0', $currency));
        }, $snapshot->bookId);
    }

    /** @return list<PartyBalance> */
    public function partyBalances(Snapshot $snapshot, string $accountId, int $limit = 1000, ?string $afterPartyId = null, bool $includeZero = false): array
    {
        Assert::that($limit >= 1 && $limit <= 10000, 'Invalid party balance page size.');

        return $this->db->run(Permission::Read, function () use ($snapshot, $accountId, $limit, $afterPartyId, $includeZero): array {
            $book = $this->db->find('ledger_books', $snapshot->bookId);
            $account = $this->db->find('ledger_accounts', $accountId, $snapshot->bookId);
            $functional = $this->db->currency(Row::text($book, 'currency_code'));
            $currency = $this->db->currency(Row::nullable($account, 'currency_code') ?? Row::text($book, 'currency_code'));
            $signed = "coalesce(sum(CASE WHEN p.side = 'debit' THEN p.%1\$s ELSE -p.%1\$s END), 0) AS %2\$s";
            $totals = $this->postings($snapshot)->where('p.account_id', Assert::uuid($accountId))->whereColumn('p.party_id', 'pa.id')
                ->selectRaw('count(*) AS postings, '.sprintf($signed, 'amount_minor', 'balance').', '.sprintf($signed, 'functional_minor', 'functional'));
            $query = $this->db->connection->table('ledger_parties AS pa')->joinLateral($totals, 'totals')->where('pa.tenant_id', $this->db->scope->tenantId)
                ->where('pa.entity_id', Row::text($book, 'entity_id'))->where('totals.postings', '>', 0)
                ->select('pa.id AS party_id')->selectRaw('totals.balance::text AS balance, totals.functional::text AS functional')->orderBy('pa.id')->limit($limit);
            if ($afterPartyId !== null) {
                $query->where('pa.id', '>', Assert::uuid($afterPartyId));
            }
            if (! $includeZero) {
                $query->whereRaw('(totals.balance <> 0 OR totals.functional <> 0)');
            }

            $balances = [];
            foreach ($query->get() as $row) {
                $balances[] = new PartyBalance(Row::text($row, 'party_id'), Money::minor(Row::text($row, 'balance'), $currency), Money::minor(Row::text($row, 'functional'), $functional));
            }

            return $balances;
        }, $snapshot->bookId);
    }

    public function partyStatement(Snapshot $snapshot, string $accountId, string $partyId, string $startsOn = '0001-01-01', int $limit = 1000, ?string $after = null): PartyStatement
    {
        Assert::date($startsOn);
        Assert::that($startsOn <= $snapshot->effectiveOn, 'Statement starts after it ends.');
        Assert::that($limit >= 1 && $limit <= 10000, 'Invalid statement page size.');
        $position = $after === null ? null : self::position($after);

        return $this->db->run(Permission::Read, function () use ($snapshot, $accountId, $partyId, $startsOn, $limit, $position): PartyStatement {
            $book = $this->db->find('ledger_books', $snapshot->bookId);
            $account = $this->db->find('ledger_accounts', $accountId, $snapshot->bookId);
            $functional = $this->db->currency(Row::text($book, 'currency_code'));
            $currency = $this->db->currency(Row::nullable($account, 'currency_code') ?? Row::text($book, 'currency_code'));
            $base = $this->postings($snapshot)->where('p.account_id', $accountId)->where('p.party_id', Assert::uuid($partyId));
            $before = $position === null ? 'j.effective_on < ?' : 'j.effective_on < ? OR (j.effective_on, j.sequence, p.line_no) <= (?::date, ?::bigint, ?::integer)';
            $totals = (clone $base)->selectRaw(self::signedSum('amount_minor', 'j.effective_on < ?').' AS opening, '.self::signedSum('functional_minor', 'j.effective_on < ?').' AS functional_opening, '
                .self::signedSum('amount_minor', $before).' AS brought, '.self::signedSum('functional_minor', $before).' AS functional_brought, '
                .self::signedSum('amount_minor', 'true').' AS closing, '.self::signedSum('functional_minor', 'true').' AS functional_closing',
                [$startsOn, $startsOn, $startsOn, ...($position ?? []), $startsOn, ...($position ?? [])])->first();
            Assert::that($totals instanceof stdClass, 'Cannot read statement totals.');
            $balance = Money::minor(Row::text($totals, 'brought'), $currency);
            $functionalBalance = Money::minor(Row::text($totals, 'functional_brought'), $functional);
            $zero = Money::minor('0', $currency);
            $query = $base->where('j.effective_on', '>=', $startsOn)->select('p.*', 'j.effective_on', 'j.sequence', 'j.description AS journal_description')
                ->orderBy('j.effective_on')->orderBy('j.sequence')->orderBy('p.line_no')->limit($limit + 1);
            if ($position !== null) {
                $query->whereRaw('(j.effective_on, j.sequence, p.line_no) > (?::date, ?::bigint, ?::integer)', $position);
            }
            $rows = $query->get();
            $lines = [];
            foreach ($rows->take($limit) as $row) {
                $amount = Money::minor(Row::text($row, 'amount_minor'), $currency);
                $debit = Row::text($row, 'side') === Side::Debit->value;
                $balance = $debit ? $balance->plus($amount) : $balance->minus($amount);
                $functionalAmount = Money::minor(Row::text($row, 'functional_minor'), $functional);
                $functionalBalance = $debit ? $functionalBalance->plus($functionalAmount) : $functionalBalance->minus($functionalAmount);
                $description = Row::text($row, 'description');
                $lines[] = ['journal_id' => Row::text($row, 'journal_id'), 'line_no' => (int) Row::text($row, 'line_no'), 'effective_on' => Row::text($row, 'effective_on'),
                    'sequence' => Row::text($row, 'sequence'), 'due_on' => Row::nullable($row, 'due_on'),
                    'description' => $description === '' ? Row::text($row, 'journal_description') : $description,
                    'debit' => $debit ? $amount : $zero, 'credit' => $debit ? $zero : $amount, 'balance' => $balance, 'functional_balance' => $functionalBalance,
                    'cursor' => Row::text($row, 'effective_on').'.'.Row::text($row, 'sequence').'.'.Row::text($row, 'line_no')];
            }

            return new PartyStatement($accountId, $partyId, $startsOn, $snapshot->effectiveOn, Money::minor(Row::text($totals, 'opening'), $currency),
                Money::minor(Row::text($totals, 'functional_opening'), $functional), $lines, Money::minor(Row::text($totals, 'closing'), $currency),
                Money::minor(Row::text($totals, 'functional_closing'), $functional), $rows->count() > $limit ? $lines[$limit - 1]['cursor'] : null);
        }, $snapshot->bookId);
    }

    /** @param array<string, string> $dimensions
     * @return list<array{account_id: string, code: string, name: string, type: AccountType, dimensions: array<string, string>, budget: Money, actual: Money, variance: Money}>
     */
    public function budgetVariance(Snapshot $snapshot, string $budgetId, string $startsOn, array $dimensions = [], bool $includeUnbudgeted = false): array
    {
        Assert::date($startsOn);
        Assert::that($startsOn <= $snapshot->effectiveOn, 'Report starts after it ends.');

        return $this->db->run(Permission::Read, function () use ($snapshot, $budgetId, $startsOn, $dimensions, $includeUnbudgeted): array {
            $book = $this->db->find('ledger_books', $snapshot->bookId);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $budget = $this->db->find('ledger_budgets', $budgetId, $snapshot->bookId);
            $groups = [];
            $budgeted = [];
            foreach (Row::json($budget, 'lines') as $line) {
                Assert::that(is_array($line), 'Invalid stored budget.');
                $line = (object) $line;
                $lineDimensions = Row::stringMap($line->dimensions ?? null);
                $accountId = Row::text($line, 'account_id');
                $budgeted[$accountId] = true;
                $lineStart = Row::text($line, 'starts_on');
                $lineEnd = Row::text($line, 'ends_on');
                if ($lineEnd < $startsOn || $lineStart > $snapshot->effectiveOn || array_diff_assoc($dimensions, $lineDimensions) !== []) {
                    continue;
                }
                Assert::that($lineStart >= $startsOn && $lineEnd <= $snapshot->effectiveOn, 'A budget line from '.$lineStart.' to '.$lineEnd.' partially overlaps the report range; align the range to budget lines.');
                ksort($lineDimensions);
                $group = $accountId.'|'.Canonical::json($lineDimensions);
                $groups[$group] ??= ['account_id' => $accountId, 'dimensions' => $lineDimensions, 'budget' => Money::minor('0', $currency)];
                $groups[$group]['budget'] = $groups[$group]['budget']->plus(Money::minor(Row::text($line, 'amount_minor'), $currency));
            }
            if ($includeUnbudgeted) {
                $active = $this->postings($snapshot, $dimensions)->join('ledger_accounts AS a', function (JoinClause $join): void {
                    $join->on('a.tenant_id', '=', 'p.tenant_id')->on('a.book_id', '=', 'p.book_id')->on('a.id', '=', 'p.account_id');
                })->whereIn('a.type', ['income', 'expense'])->where('j.effective_on', '>=', $startsOn)->where('j.closing', false)->distinct()->pluck('p.account_id');
                foreach ($active as $accountId) {
                    if (is_string($accountId) && ! isset($budgeted[$accountId])) {
                        $groups[$accountId.'|unbudgeted'] = ['account_id' => $accountId, 'dimensions' => $dimensions, 'budget' => Money::minor('0', $currency)];
                    }
                }
            }
            $accounts = $this->db->table('ledger_accounts')->where('book_id', $snapshot->bookId)->get()->keyBy('id');
            $rows = [];
            foreach ($groups as $group) {
                $actual = $this->postings($snapshot, [...$dimensions, ...$group['dimensions']])->where('p.account_id', $group['account_id'])
                    ->where('j.effective_on', '>=', $startsOn)->where('j.closing', false)
                    ->selectRaw("coalesce(sum(CASE WHEN p.side = 'debit' THEN p.functional_minor ELSE -p.functional_minor END), 0)::text AS movement")->value('movement');
                $actual = Money::minor(is_string($actual) ? $actual : '0', $currency);
                $account = $accounts->get($group['account_id']);
                Assert::that($account instanceof stdClass, 'Budget account is missing.');
                $rows[] = ['account_id' => $group['account_id'], 'code' => Row::text($account, 'code'), 'name' => Row::text($account, 'name'),
                    'type' => AccountType::from(Row::text($account, 'type')), 'dimensions' => $group['dimensions'],
                    'budget' => $group['budget'], 'actual' => $actual, 'variance' => $actual->minus($group['budget'])];
            }
            usort($rows, fn (array $a, array $b): int => [$a['code'], Canonical::json($a['dimensions'])] <=> [$b['code'], Canonical::json($b['dimensions'])]);

            return $rows;
        }, $snapshot->bookId);
    }

    /** @param array<string, string> $dimensions
     * @return list<LedgerEntry>
     */
    public function generalLedger(Snapshot $snapshot, ?string $accountId = null, int $limit = 500, string $afterSequence = '0', int $afterLine = 0, array $dimensions = []): array
    {
        Assert::that($limit >= 1 && $limit <= 10000 && $afterLine >= 0, 'Invalid ledger page size or cursor.');

        return $this->db->run(Permission::Read, function () use ($snapshot, $accountId, $limit, $afterSequence, $afterLine, $dimensions): array {
            $book = $this->db->find('ledger_books', $snapshot->bookId);
            $functional = $this->db->currency(Row::text($book, 'currency_code'));
            $query = $this->postings($snapshot, $dimensions)->select('p.*', 'j.effective_on', 'j.recorded_at', 'j.sequence', 'j.description AS journal_description', 'j.metadata', 'j.actor_id', 'j.firm_id', 'j.reverses_id', 'j.closing', 'j.source_type', 'j.source_reference')
                ->whereRaw('(j.sequence, p.line_no) > (?::bigint, ?::integer)', [$afterSequence, $afterLine])->orderBy('j.sequence')->orderBy('p.line_no')->limit($limit);

            if ($accountId !== null) {
                $query->where('p.account_id', Assert::uuid($accountId));
            }

            $currencies = [];
            $entries = [];
            foreach ($query->get() as $row) {
                $code = Row::text($row, 'currency_code');
                $currencies[$code] ??= $this->db->currency($code);
                $journal = Journal::fromRow((object) [...(array) $row, 'id' => Row::text($row, 'journal_id'), 'description' => Row::text($row, 'journal_description')]);
                $entries[] = new LedgerEntry(Row::text($row, 'id'), Row::text($row, 'tenant_id'), Row::text($row, 'created_at'), $journal, JournalLine::fromRow($row, $currencies[$code], $functional));
            }

            return $entries;
        }, $snapshot->bookId);
    }

    /** @param non-empty-list<StatementColumn> $columns */
    public function statement(StatementDefinition $definition, array $columns): Statement
    {
        Assert::that($columns !== [] && count($columns) <= 120 && array_is_list($columns), 'A statement requires between 1 and 120 ordered columns.');
        $bookId = $columns[0]->snapshot->bookId;

        return $this->db->run(Permission::Read, function () use ($definition, $columns, $bookId): Statement {
            $book = $this->db->find('ledger_books', $bookId);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $accounts = [];
            $children = [];
            foreach ($this->db->table('ledger_accounts')->where('book_id', $bookId)->get() as $account) {
                $accounts[Row::text($account, 'code')] = $account;
                $parent = Row::nullable($account, 'parent_id');
                if ($parent !== null) {
                    $children[$parent][] = Row::text($account, 'code');
                }
            }

            $selectors = [];
            $covered = [];
            foreach ($definition->rows as $key => $row) {
                $selected = [];
                $pending = $row['accounts'] ?? [];
                while ($pending !== []) {
                    $code = array_pop($pending);
                    Assert::that(isset($accounts[$code]), 'Unknown statement account code: '.$code);
                    $account = $accounts[$code];
                    $id = Row::text($account, 'id');
                    if (Row::boolean($account, 'is_group')) {
                        array_push($pending, ...($children[$id] ?? []));
                    } else {
                        $selected[$id] = true;
                        $covered[$id] = true;
                    }
                }
                $selectors[$key] = $selected;
            }

            $rows = [];
            foreach ($definition->rows as $key => $row) {
                $rows[$key] = ['label' => $row['label'], 'note' => $row['note'] ?? null, 'values' => []];
            }
            $keys = [];
            foreach ($columns as $column) {
                Assert::that($column->snapshot->bookId === $bookId && ! isset($keys[$column->key]), 'Statement columns require the same book and unique keys.');
                Assert::that(BigInteger::of($column->snapshot->throughSequence)->isLessThanOrEqualTo(Row::text($book, 'next_sequence')), 'A statement cannot use a future sequence cutoff.');
                $keys[$column->key] = true;
                $cache = [];
                $values = [];
                foreach ($definition->rows as $key => $row) {
                    if (isset($row['terms'])) {
                        continue;
                    }
                    $includeClosing = $row['include_closing'] ?? true;
                    $cacheKey = $includeClosing ? 'with_closing' : 'without_closing';
                    $cache[$cacheKey] ??= $this->trialBalance($column->snapshot, $column->startsOn, $column->dimensions, $includeClosing);
                    $total = Money::minor('0', $currency);
                    foreach ($cache[$cacheKey] as $balance) {
                        if (isset($selectors[$key][$balance->accountId])) {
                            $amount = match ($row['basis'] ?? 'movement') {
                                'opening' => $balance->opening,
                                'closing' => $balance->closing,
                                'debits' => $balance->debits,
                                'credits' => $balance->credits,
                                default => $balance->debits->minus($balance->credits),
                            };
                            $total = $total->plus($amount);
                        }
                    }
                    $values[$key] = ($row['sign'] ?? 'debit') === 'credit' ? $total->negated() : $total;
                }

                if ($definition->coverageTypes !== []) {
                    foreach ($this->trialBalance($column->snapshot, $column->startsOn, $column->dimensions, false) as $balance) {
                        if (in_array($balance->type->value, $definition->coverageTypes, true)
                            && (! $balance->opening->minor->isZero() || ! $balance->debits->minor->isZero() || ! $balance->credits->minor->isZero())) {
                            Assert::that(isset($covered[$balance->accountId]), 'Statement omits an active account: '.$balance->code);
                        }
                    }
                }

                foreach ($rows as $key => $row) {
                    $row['values'][$column->key] = $this->statementValue($definition, $key, $values, $currency);
                    $rows[$key] = $row;
                }
            }

            return new Statement($definition, $columns, $currency, array_keys($definition->rows), $rows);
        }, $bookId);
    }

    /** @param non-empty-list<StatementColumn> $columns */
    public function saveStatement(string $bookId, string $key, StatementDefinition $definition, array $columns, ?string $configurationId = null): string
    {
        Assert::text($key, 128);

        return $this->db->run(Permission::Read, function () use ($bookId, $key, $definition, $columns, $configurationId): string {
            $this->db->lockBook($bookId);
            Assert::that($columns !== [] && $columns[0]->snapshot->bookId === $bookId, 'Statement columns belong to another book.');
            $configuration = null;
            if ($configurationId !== null) {
                Assert::that($this->configurations !== null, 'Configured statements require a configuration service.');
                $configured = $this->configurations->statement($bookId, $configurationId, $definition->name);
                Assert::that(Canonical::hash($configured) === Canonical::hash($definition), 'Saved statement differs from its pinned configuration.');
                foreach ($columns as $column) {
                    $this->configurations->get($bookId, $configurationId, $column->startsOn);
                    $this->configurations->get($bookId, $configurationId, $column->snapshot->effectiveOn);
                }
                $pack = $this->configurations->get($bookId, $configurationId);
                $configuration = ['id' => $configurationId, 'content_hash' => $pack['content_hash'], 'definition_hash' => $pack['definition_hash']];
            }
            $fingerprint = Canonical::hash($configuration === null ? [$definition, $columns] : [$definition, $columns, $configuration]);
            $existing = $this->db->table('ledger_statement_runs')->where('book_id', $bookId)->where('idempotency_key', $key)->first();
            if ($existing instanceof stdClass) {
                Assert::that(Row::text($existing, 'fingerprint') === $fingerprint, 'Statement key was already used with different inputs.');

                return Row::text($existing, 'id');
            }
            $result = Canonical::json([...$this->statement($definition, $columns)->jsonSerialize(), ...($configuration === null ? [] : ['configuration' => $configuration])]);
            $id = (string) Str::uuid7();
            $this->db->insert('ledger_statement_runs', ['id' => $id, 'book_id' => $bookId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
                'result_json' => $result]);

            return $id;
        }, $bookId);
    }

    /** @param non-empty-list<StatementColumn> $columns */
    public function saveConfiguredStatement(string $bookId, string $key, string $configurationId, string $name, array $columns): string
    {
        Assert::that($this->configurations !== null, 'Configured statements require a configuration service.');

        return $this->saveStatement($bookId, $key, $this->configurations->statement($bookId, $configurationId, $name), $columns, $configurationId);
    }

    /** @return array{result_json: string, result_hash: string, result: Statement} */
    public function statementEvidence(string $bookId, string $reportId): array
    {
        return $this->db->run(Permission::Read, function () use ($bookId, $reportId): array {
            $row = $this->db->find('ledger_statement_runs', $reportId, $bookId);
            $json = Row::text($row, 'result_json');
            Assert::that(hash_equals(Row::text($row, 'result_hash'), hash('sha256', $json)), 'Statement evidence hash does not match.');

            return ['result_json' => $json, 'result_hash' => Row::text($row, 'result_hash'), 'result' => Statement::fromArray(Row::jsonObject($row, 'result_json'))];
        }, $bookId);
    }

    /** @param array<string, Money> $values */
    private function statementValue(StatementDefinition $definition, string $key, array &$values, Currency $currency): Money
    {
        if (isset($values[$key])) {
            return $values[$key];
        }
        $total = BigInteger::zero();
        foreach ($definition->rows[$key]['terms'] ?? [] as $reference => $coefficient) {
            $total = $total->plus($this->statementValue($definition, $reference, $values, $currency)->minor->multipliedBy($coefficient));
        }
        $result = Money::minor((string) $total, $currency);

        return $values[$key] = ($definition->rows[$key]['sign'] ?? 'debit') === 'credit' ? $result->negated() : $result;
    }

    /** @param array<string, string> $dimensions
     * @return array<string, array{opening: BigInteger, debits: BigInteger, credits: BigInteger}>
     */
    private function restatementAdjustments(Snapshot $snapshot, string $startsOn, array $dimensions, bool $includeClosing): array
    {
        $restatements = $this->db->connection->table('ledger_restatements AS r')->join('ledger_journals AS j', function (JoinClause $join): void {
            $join->on('j.tenant_id', '=', 'r.tenant_id')->on('j.book_id', '=', 'r.book_id')->on('j.id', '=', 'r.journal_id');
        })->join('ledger_year_closures AS c', function (JoinClause $join): void {
            $join->on('c.tenant_id', '=', 'r.tenant_id')->on('c.book_id', '=', 'r.book_id')->on('c.id', '=', 'r.closure_id');
        })->where('r.tenant_id', $this->db->scope->tenantId)->where('r.book_id', $snapshot->bookId)->where('j.sequence', '<=', $snapshot->throughSequence)
            ->select('r.journal_id', 'r.retained_account_id', 'r.attribution', 'j.effective_on', 'c.ends_on')->get();
        $adjustments = [];
        $apply = function (string $accountId, string $side, string $minor, string $date, int $direction) use (&$adjustments, $snapshot, $startsOn): void {
            if ($date > $snapshot->effectiveOn) {
                return;
            }
            $adjustments[$accountId] ??= ['opening' => BigInteger::zero(), 'debits' => BigInteger::zero(), 'credits' => BigInteger::zero()];
            $amount = BigInteger::of($minor)->multipliedBy($direction);
            if ($date < $startsOn) {
                $adjustments[$accountId]['opening'] = $adjustments[$accountId]['opening']->plus($side === 'debit' ? $amount : $amount->negated());
            } else {
                $adjustments[$accountId][$side === 'debit' ? 'debits' : 'credits'] = $adjustments[$accountId][$side === 'debit' ? 'debits' : 'credits']->plus($amount);
            }
        };
        foreach ($restatements as $restatement) {
            $postedOn = Row::text($restatement, 'effective_on');
            $yearEnd = Row::text($restatement, 'ends_on');
            $retained = Row::text($restatement, 'retained_account_id');
            $lines = $this->db->table('ledger_postings')->where('book_id', $snapshot->bookId)->where('journal_id', Row::text($restatement, 'journal_id'));
            if ($dimensions !== []) {
                $lines->whereRaw('dimensions @> ?::jsonb', [Canonical::json($dimensions)]);
            }
            foreach ($lines->get() as $line) {
                $accountId = Row::text($line, 'account_id');
                $apply($accountId, Row::text($line, 'side'), Row::text($line, 'functional_minor'), $postedOn, -1);
                if ($includeClosing || $accountId !== $retained) {
                    $apply($accountId, Row::text($line, 'side'), Row::text($line, 'functional_minor'), $yearEnd, 1);
                }
            }
            if ($includeClosing) {
                continue;
            }
            foreach (Row::json($restatement, 'attribution') as $line) {
                Assert::that(is_array($line), 'Invalid stored restatement.');
                $line = (object) $line;
                if (array_diff_assoc($dimensions, Row::stringMap($line->dimensions ?? null)) === []) {
                    $apply(Row::text($line, 'account_id'), Row::text($line, 'side'), Row::text($line, 'functional_minor'), $yearEnd, 1);
                }
            }
        }

        return $adjustments;
    }

    /** @return array{string, string, int} */
    private static function position(string $cursor): array
    {
        $parts = explode('.', $cursor);
        Assert::that(count($parts) === 3 && preg_match('/^\d{1,19}$/D', $parts[1]) === 1 && preg_match('/^\d{1,9}$/D', $parts[2]) === 1, 'Invalid cursor.');

        return [Assert::date($parts[0]), $parts[1], (int) $parts[2]];
    }

    /** @param literal-string $column
     * @param  literal-string  $filter
     * @return literal-string
     */
    private static function signedSum(string $column, string $filter): string
    {
        return "coalesce(sum(CASE WHEN p.side = 'debit' THEN p.$column ELSE -p.$column END) FILTER (WHERE $filter), 0)::text";
    }

    private function filteredOpenItems(Snapshot $snapshot, bool $paged, ?string $partyId = null, ?string $accountId = null): Builder
    {
        $this->db->snapshotBook($snapshot);
        $tenant = $this->db->scope->tenantId;
        $allocations = function (Side $side) use ($snapshot, $tenant, $paged): Builder {
            $item = $side === Side::Debit ? 'a.debit_item_id' : 'a.credit_item_id';
            $functional = $side === Side::Debit ? 'a.debit_functional_minor' : 'a.credit_functional_minor';
            $query = $this->db->connection->table('ledger_allocations AS a')->where('a.tenant_id', $tenant)->where('a.book_id', $snapshot->bookId)
                ->where('a.effective_on', '<=', $snapshot->effectiveOn)->where('a.sequence', '<=', $snapshot->throughSequence)
                ->selectRaw($item.' AS item_id')
                ->selectRaw('CASE WHEN a.reverses_id IS NULL THEN a.amount_minor ELSE -a.amount_minor END AS amount')
                ->selectRaw('CASE WHEN a.reverses_id IS NULL THEN '.$functional.' ELSE -'.$functional.' END AS functional');

            return $paged ? $query->whereColumn($item, 'oi.id') : $query;
        };
        $allocated = $this->db->connection->query()->fromSub($allocations(Side::Debit)->unionAll($allocations(Side::Credit)), 'events')
            ->selectRaw('sum(events.amount) AS amount, sum(events.functional) AS functional');
        $revalued = $this->db->connection->table('ledger_revaluations AS r')->where('r.tenant_id', $tenant)->where('r.book_id', $snapshot->bookId)
            ->where('r.effective_on', '<=', $snapshot->effectiveOn)->where('r.sequence', '<=', $snapshot->throughSequence)->selectRaw('sum(r.delta_minor) AS delta');
        $query = $this->db->connection->table('ledger_open_items AS oi')->join('ledger_postings AS p', function (JoinClause $join): void {
            $join->on('p.tenant_id', '=', 'oi.tenant_id')->on('p.book_id', '=', 'oi.book_id')->on('p.journal_id', '=', 'oi.journal_id')->on('p.line_no', '=', 'oi.line_no');
        })->where('oi.tenant_id', $tenant)->where('oi.book_id', $snapshot->bookId)->where('oi.effective_on', '<=', $snapshot->effectiveOn)
            ->where('oi.sequence', '<=', $snapshot->throughSequence)
            ->select('oi.id', 'oi.journal_id', 'oi.line_no', 'oi.party_id', 'oi.effective_on', 'oi.sequence', 'p.account_id', 'p.side', 'p.currency_code', 'p.amount_minor', 'p.dimensions')
            ->selectRaw('coalesce(p.due_on, oi.effective_on) AS due_on')
            ->selectRaw(self::OUTSTANDING.' AS outstanding_minor')
            ->selectRaw('p.functional_minor + coalesce(revalued.delta, 0) - coalesce(allocated.functional, 0) AS functional_outstanding_minor');
        if ($paged) {
            $query->joinLateral($allocated, 'allocated')->joinLateral($revalued->whereColumn('r.item_id', 'oi.id'), 'revalued');
        } else {
            $query->leftJoinSub($allocated->addSelect('events.item_id')->groupBy('events.item_id'), 'allocated', 'allocated.item_id', '=', 'oi.id')
                ->leftJoinSub($revalued->addSelect('r.item_id')->groupBy('r.item_id'), 'revalued', 'revalued.item_id', '=', 'oi.id');
        }
        if ($partyId !== null) {
            $query->where('oi.party_id', Assert::uuid($partyId));
        }
        if ($accountId !== null) {
            $query->where('p.account_id', Assert::uuid($accountId));
        }

        return $query;
    }

    /** @param array<string, string> $dimensions */
    private function agedItems(Snapshot $snapshot, ?string $partyId, ?string $accountId, array $dimensions = []): Builder
    {
        $items = $this->filteredOpenItems($snapshot, $partyId !== null, $partyId, $accountId)->whereRaw(self::OUTSTANDING.' <> 0')
            ->selectRaw('?::date - coalesce(p.due_on, oi.effective_on) AS overdue_days', [$snapshot->effectiveOn]);
        if ($dimensions !== []) {
            $items->whereRaw('p.dimensions @> ?::jsonb', [Canonical::json($dimensions)]);
        }

        return $this->db->connection->query()->fromSub($items, 'items')
            ->selectRaw('CASE WHEN items.overdue_days <= 0 THEN 0 WHEN items.overdue_days <= 30 THEN 1 WHEN items.overdue_days <= 60 THEN 2 WHEN items.overdue_days <= 90 THEN 3 ELSE 4 END AS bucket')
            ->groupBy('bucket')->orderBy('bucket');
    }

    /** @param iterable<stdClass> $rows
     * @return list<OpenItem>
     */
    private function openItemList(Snapshot $snapshot, iterable $rows): array
    {
        $functional = $this->functionalCurrency($snapshot->bookId);
        $currencies = [];
        $items = [];
        foreach ($rows as $row) {
            $code = Row::text($row, 'currency_code');
            $currency = $currencies[$code] ??= $this->db->currency($code);
            $items[] = new OpenItem(Row::text($row, 'id'), Row::text($row, 'journal_id'), (int) Row::text($row, 'line_no'), Row::text($row, 'account_id'),
                Row::text($row, 'party_id'), Side::from(Row::text($row, 'side')), Row::text($row, 'effective_on'), Row::text($row, 'due_on'),
                Money::minor(Row::text($row, 'amount_minor'), $currency), Money::minor(Row::text($row, 'outstanding_minor'), $currency),
                Money::minor(Row::text($row, 'functional_outstanding_minor'), $functional), Row::text($row, 'sequence'), Row::dimensions($row));
        }

        return $items;
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
        $this->db->snapshotBook($snapshot);
        $query = $this->db->connection->table('ledger_postings AS p')->join('ledger_journals AS j', function (JoinClause $join): void {
            $join->on('j.tenant_id', '=', 'p.tenant_id')->on('j.book_id', '=', 'p.book_id')->on('j.id', '=', 'p.journal_id');
        })->where('p.tenant_id', $this->db->scope->tenantId)->where('p.book_id', $snapshot->bookId)->where('j.effective_on', '<=', $snapshot->effectiveOn)->where('j.sequence', '<=', $snapshot->throughSequence);

        if ($dimensions !== []) {
            $query->whereRaw('p.dimensions @> ?::jsonb', [Canonical::json($dimensions)]);
        }

        return $query;
    }
}
