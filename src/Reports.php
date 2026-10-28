<?php

declare(strict_types=1);

namespace Noria\Ledger;

use Brick\Math\BigInteger;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Reporting\AccountBalance;
use Noria\Ledger\Reporting\CashFlow;
use Noria\Ledger\Reporting\OpenItem;
use Noria\Ledger\Reporting\PartyStatement;
use Noria\Ledger\Reporting\Snapshot;
use Noria\Ledger\Reporting\StatementColumn;
use Noria\Ledger\Reporting\StatementDefinition;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Canonical;
use Noria\Ledger\Support\Row;
use stdClass;

readonly class Reports
{
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
                ->select('oi.id AS open_item_id', 'p.*', 'j.effective_on')->selectRaw("coalesce(allocated.amount, '0') AS allocated_minor, coalesce(allocated.functional, '0') AS allocated_functional")
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
                    $result[] = new OpenItem(Row::text($row, 'open_item_id'), Row::text($row, 'journal_id'), (int) Row::text($row, 'line_no'), Row::text($row, 'account_id'), Row::text($row, 'party_id'),
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

    public function partyStatement(Snapshot $snapshot, string $accountId, string $partyId, string $startsOn = '0001-01-01'): PartyStatement
    {
        Assert::date($startsOn);
        Assert::that($startsOn <= $snapshot->effectiveOn, 'Statement starts after it ends.');

        return $this->db->run(Permission::Read, function () use ($snapshot, $accountId, $partyId, $startsOn): PartyStatement {
            $book = $this->db->find('ledger_books', $snapshot->bookId);
            $account = $this->db->find('ledger_accounts', $accountId, $snapshot->bookId);
            $functional = $this->db->currency(Row::text($book, 'currency_code'));
            $currency = $this->db->currency(Row::nullable($account, 'currency_code') ?? Row::text($book, 'currency_code'));
            $signed = "CASE WHEN p.side = 'debit' THEN %s ELSE -%s END";
            $base = $this->postings($snapshot)->where('p.account_id', $accountId)->where('p.party_id', Assert::uuid($partyId));
            $opening = (clone $base)->where('j.effective_on', '<', $startsOn)
                ->selectRaw('coalesce(sum('.sprintf($signed, 'p.amount_minor', 'p.amount_minor').'), 0)::text AS amount')
                ->selectRaw('coalesce(sum('.sprintf($signed, 'p.functional_minor', 'p.functional_minor').'), 0)::text AS functional')->first();
            $balance = Money::minor($opening instanceof stdClass ? Row::text($opening, 'amount') : '0', $currency);
            $functionalBalance = Money::minor($opening instanceof stdClass ? Row::text($opening, 'functional') : '0', $functional);
            $openingBalance = $balance;
            $openingFunctional = $functionalBalance;
            $zero = Money::minor('0', $currency);
            $lines = [];
            $rows = $base->where('j.effective_on', '>=', $startsOn)->select('p.*', 'j.effective_on', 'j.sequence', 'j.description AS journal_description')
                ->orderBy('j.effective_on')->orderBy('j.sequence')->orderBy('p.line_no')->get();
            foreach ($rows as $row) {
                $amount = Money::minor(Row::text($row, 'amount_minor'), $currency);
                $debit = Row::text($row, 'side') === Side::Debit->value;
                $balance = $debit ? $balance->plus($amount) : $balance->minus($amount);
                $functionalAmount = Money::minor(Row::text($row, 'functional_minor'), $functional);
                $functionalBalance = $debit ? $functionalBalance->plus($functionalAmount) : $functionalBalance->minus($functionalAmount);
                $description = Row::text($row, 'description');
                $lines[] = ['journal_id' => Row::text($row, 'journal_id'), 'line_no' => (int) Row::text($row, 'line_no'), 'effective_on' => Row::text($row, 'effective_on'),
                    'sequence' => Row::text($row, 'sequence'), 'due_on' => Row::nullable($row, 'due_on'),
                    'description' => $description === '' ? Row::text($row, 'journal_description') : $description,
                    'debit' => $debit ? $amount : $zero, 'credit' => $debit ? $zero : $amount, 'balance' => $balance, 'functional_balance' => $functionalBalance];
            }

            return new PartyStatement($accountId, $partyId, $startsOn, $snapshot->effectiveOn, $openingBalance, $openingFunctional, $lines, $balance, $functionalBalance);
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
                $lineDimensions = [];
                foreach ((array) ($line->dimensions ?? []) as $key => $value) {
                    if (is_string($key) && is_string($value)) {
                        $lineDimensions[$key] = $value;
                    }
                }
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
                $account = $account instanceof stdClass ? $account : new stdClass;
                $rows[] = ['account_id' => $group['account_id'], 'code' => Row::text($account, 'code'), 'name' => Row::text($account, 'name'),
                    'type' => AccountType::from(Row::text($account, 'type')), 'dimensions' => $group['dimensions'],
                    'budget' => $group['budget'], 'actual' => $actual, 'variance' => $actual->minus($group['budget'])];
            }
            usort($rows, fn (array $a, array $b): int => [$a['code'], Canonical::json($a['dimensions'])] <=> [$b['code'], Canonical::json($b['dimensions'])]);

            return $rows;
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

    /** @param non-empty-list<StatementColumn> $columns
     * @return array<string, mixed>
     */
    public function statement(StatementDefinition $definition, array $columns): array
    {
        Assert::that($columns !== [] && count($columns) <= 120 && array_is_list($columns), 'A statement requires between 1 and 120 ordered columns.');
        $bookId = $columns[0]->snapshot->bookId;

        return $this->db->run(Permission::Read, function () use ($definition, $columns, $bookId): array {
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

                foreach (array_keys($definition->rows) as $key) {
                    $rows[$key]['values'][$column->key] = $this->statementValue($definition, $key, $values, $currency);
                }
            }

            return ['definition' => $definition, 'columns' => $columns, 'currency' => $currency->code, 'row_order' => array_keys($definition->rows), 'rows' => $rows];
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
                if ($this->configurations === null) {
                    throw new \LogicException('Missing configuration service.');
                }
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
            $result = Canonical::json([...$this->statement($definition, $columns), ...($configuration === null ? [] : ['configuration' => $configuration])]);
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
        if ($this->configurations === null) {
            throw new \LogicException('Missing configuration service.');
        }

        return $this->saveStatement($bookId, $key, $this->configurations->statement($bookId, $configurationId, $name), $columns, $configurationId);
    }

    /** @return array{result_json: string, result_hash: string, result: array<string, mixed>} */
    public function statementEvidence(string $bookId, string $reportId): array
    {
        return $this->db->run(Permission::Read, function () use ($bookId, $reportId): array {
            $row = $this->db->find('ledger_statement_runs', $reportId, $bookId);
            $json = Row::text($row, 'result_json');
            Assert::that(hash_equals(Row::text($row, 'result_hash'), hash('sha256', $json)), 'Statement evidence hash does not match.');

            return ['result_json' => $json, 'result_hash' => Row::text($row, 'result_hash'), 'result' => Row::jsonObject($row, 'result_json')];
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
                $lineDimensions = [];
                foreach ((array) ($line->dimensions ?? []) as $name => $value) {
                    if (is_string($name) && is_string($value)) {
                        $lineDimensions[$name] = $value;
                    }
                }
                if (array_diff_assoc($dimensions, $lineDimensions) === []) {
                    $apply(Row::text($line, 'account_id'), Row::text($line, 'side'), Row::text($line, 'functional_minor'), $yearEnd, 1);
                }
            }
        }

        return $adjustments;
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
