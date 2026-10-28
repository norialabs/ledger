<?php

declare(strict_types=1);

namespace Noria\Ledger;

use Brick\Math\BigInteger;
use Brick\Math\BigRational;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\AllocationRequest;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Accounting\Source;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Money\Rounding;
use Noria\Ledger\Reporting\OpenItem;
use Noria\Ledger\Reporting\Snapshot;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Canonical;
use Noria\Ledger\Support\Row;
use stdClass;

readonly class Settlements
{
    private const AGING_BUCKETS = ['current', '1-30', '31-60', '61-90', '91+'];

    public function __construct(private ScopedDatabase $db, private Journals $journals, private Revaluations $revaluations, private ?Reports $reports = null) {}

    public function allocate(AllocationRequest $request): string
    {
        return $this->db->run(Permission::Allocate, function () use ($request): string {
            $book = $this->db->lockBook($request->bookId);
            $fingerprint = Canonical::hash($request);
            $replayed = $this->replay($request->bookId, $request->key, $fingerprint);

            if ($replayed !== null) {
                return $replayed;
            }

            $this->clearRevaluations($request->bookId, [$request->debitItemId, $request->creditItemId], $request->key, $request->effectiveOn);
            $debit = $this->item($request->bookId, $request->debitItemId);
            $credit = $this->item($request->bookId, $request->creditItemId);
            $currency = $this->db->currency(Row::text($debit, 'currency_code'));
            Assert::that($currency == $request->amount->currency, 'Allocation currency or scale mismatch.');
            $debitFunctional = $this->functionalShare($request->bookId, $request->debitItemId, $debit, $request->amount->minor, Side::Debit);
            $creditFunctional = $this->functionalShare($request->bookId, $request->creditItemId, $credit, $request->amount->minor, Side::Credit);
            $id = (string) Str::uuid7();
            $fxId = null;
            $difference = $creditFunctional->minus($debitFunctional);

            if (! $difference->isZero()) {
                $gain = $difference->isPositive();
                $accountId = $gain ? $request->gainAccountId : $request->lossAccountId;
                Assert::that($accountId !== null, 'Settlement requires an exchange gain or loss account.');
                $account = $this->db->find('ledger_accounts', $accountId, $request->bookId);
                Assert::that(Row::text($account, 'type') === ($gain ? AccountType::Income : AccountType::Expense)->value, 'Incorrect exchange gain or loss account type.');
                $functionalCurrency = $this->db->currency(Row::text($book, 'currency_code'));
                $valuation = Money::minor((string) $difference->abs(), $functionalCurrency);
                $rate = new ExchangeRate($currency, $functionalCurrency, Row::text($debit, 'rate'), Row::text($debit, 'rate_as_of'), Row::text($debit, 'rate_source'), Rounding::from(Row::text($debit, 'rounding')));
                $fxId = $this->journals->post(new JournalRequest($request->bookId, 'fx:'.hash('sha256', $request->key), $request->effectiveOn, 'Realized exchange difference', [
                    new Posting(Row::text($debit, 'account_id'), $gain ? Side::Debit : Side::Credit, Money::minor('0', $currency), $rate,
                        Row::text($debit, 'party_id'), dimensions: Row::dimensions($debit), valuation: $valuation),
                    new Posting($accountId, $gain ? Side::Credit : Side::Debit, $valuation, dimensions: Row::dimensions($debit)),
                ], ['allocation_id' => $id, 'policy' => 'realized-fx:v1']))->id;
            }

            $this->db->insert('ledger_allocations', ['id' => $id, 'book_id' => $request->bookId, 'idempotency_key' => $request->key, 'fingerprint' => $fingerprint,
                'debit_item_id' => $request->debitItemId, 'credit_item_id' => $request->creditItemId, 'amount_minor' => (string) $request->amount->minor,
                'debit_functional_minor' => (string) $debitFunctional, 'credit_functional_minor' => (string) $creditFunctional,
                'effective_on' => $request->effectiveOn, 'fx_journal_id' => $fxId, 'reason' => $request->reason]);

            return $id;
        }, $request->bookId);
    }

    public function release(string $bookId, string $allocationId, string $key, string $effectiveOn, string $reason): string
    {
        Assert::text($key, 128);
        Assert::date($effectiveOn);
        Assert::text($reason, 1024);

        return $this->db->run(Permission::Allocate, function () use ($bookId, $allocationId, $key, $effectiveOn, $reason): string {
            $this->db->lockBook($bookId);
            $fingerprint = Canonical::hash(['release' => $allocationId, 'effective_on' => $effectiveOn, 'reason' => $reason]);
            $replayed = $this->replay($bookId, $key, $fingerprint);

            if ($replayed !== null) {
                return $replayed;
            }

            $original = $this->db->find('ledger_allocations', $allocationId, $bookId);
            $this->clearRevaluations($bookId, [Row::text($original, 'debit_item_id'), Row::text($original, 'credit_item_id')], $key, $effectiveOn);
            $fxId = Row::nullable($original, 'fx_journal_id');
            $reversal = $fxId === null ? null : $this->journals->reverse($bookId, $fxId, 'fx-release:'.hash('sha256', $key), $effectiveOn, $reason)->id;
            $id = (string) Str::uuid7();
            $this->db->insert('ledger_allocations', ['id' => $id, 'book_id' => $bookId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
                'debit_item_id' => Row::text($original, 'debit_item_id'), 'credit_item_id' => Row::text($original, 'credit_item_id'),
                'amount_minor' => Row::text($original, 'amount_minor'), 'debit_functional_minor' => Row::text($original, 'debit_functional_minor'),
                'credit_functional_minor' => Row::text($original, 'credit_functional_minor'), 'effective_on' => $effectiveOn,
                'reverses_id' => $allocationId, 'fx_journal_id' => $reversal, 'reason' => $reason]);

            return $id;
        }, $bookId);
    }

    /** @return list<string> */
    public function allocateOldest(string $bookId, string $itemId, string $effectiveOn, string $reason, ?string $gainAccountId = null, ?string $lossAccountId = null): array
    {
        Assert::date($effectiveOn);
        Assert::text($reason, 1024);
        Assert::that($this->reports !== null, 'Automatic allocation requires the reports service.');

        return $this->db->run(Permission::Allocate, function () use ($bookId, $itemId, $effectiveOn, $reason, $gainAccountId, $lossAccountId): array {
            $book = $this->db->lockBook($bookId);
            $reports = $this->reports ?? throw new \LogicException('Missing reports service.');
            $item = $this->item($bookId, $itemId);
            $side = Side::from(Row::text($item, 'side'));
            $dimensions = Row::dimensions($item);
            $items = $reports->openItems(new Snapshot($bookId, $effectiveOn, Row::text($book, 'next_sequence')), Row::text($item, 'party_id'));
            $source = null;
            $targets = [];
            foreach ($items as $candidate) {
                if ($candidate->id === $itemId) {
                    $source = $candidate;
                } elseif ($candidate->side !== $side && $candidate->accountId === Row::text($item, 'account_id')
                    && $candidate->outstanding->currency == $this->db->currency(Row::text($item, 'currency_code'))
                    && Row::dimensions($this->item($bookId, $candidate->id)) == $dimensions) {
                    $targets[] = $candidate;
                }
            }
            Assert::that($source !== null, 'The item has nothing left to allocate.');
            usort($targets, fn (OpenItem $a, OpenItem $b): int => [$a->dueOn, $a->effectiveOn] <=> [$b->dueOn, $b->effectiveOn]);
            $remaining = $source->outstanding;
            $allocations = [];
            foreach ($targets as $target) {
                if ($remaining === null || ! $remaining->minor->isPositive()) {
                    break;
                }
                $amount = $remaining->minor->isGreaterThan($target->outstanding->minor) ? $target->outstanding : $remaining;
                $allocations[] = $this->allocate(new AllocationRequest($bookId, 'oldest:'.hash('sha256', $itemId.':'.$target->id.':'.$amount->minor.':'.$effectiveOn),
                    $side === Side::Debit ? $itemId : $target->id, $side === Side::Debit ? $target->id : $itemId, $amount, $effectiveOn, $reason, $gainAccountId, $lossAccountId));
                $remaining = $remaining->minus($amount);
            }

            return $allocations;
        }, $bookId);
    }

    public function writeOff(string $bookId, string $key, string $itemId, Money $amount, string $accountId, string $effectiveOn, string $reason,
        ?string $gainAccountId = null, ?string $lossAccountId = null): string
    {
        Assert::text($key, 128);
        Assert::date($effectiveOn);
        Assert::text($reason, 1024);
        Assert::that($amount->minor->isPositive(), 'A write-off amount must be positive.');

        return $this->db->run(Permission::Allocate, function () use ($bookId, $key, $itemId, $amount, $accountId, $effectiveOn, $reason, $gainAccountId, $lossAccountId): string {
            $book = $this->db->lockBook($bookId);
            $fingerprint = Canonical::hash(['item' => $itemId, 'amount' => $amount, 'account' => $accountId, 'effective_on' => $effectiveOn, 'reason' => $reason,
                'gain' => $gainAccountId, 'loss' => $lossAccountId]);
            $existing = $this->db->table('ledger_write_offs')->where('book_id', $bookId)->where('idempotency_key', $key)->first();
            if ($existing instanceof stdClass) {
                Assert::that(hash_equals(Row::text($existing, 'fingerprint'), $fingerprint), 'Idempotency key was already used for a different write-off.');

                return Row::text($existing, 'id');
            }
            $item = $this->item($bookId, $itemId);
            $currency = $this->db->currency(Row::text($item, 'currency_code'));
            Assert::that($currency == $amount->currency, 'Write-off currency or scale mismatch.');
            $functional = $this->db->currency(Row::text($book, 'currency_code'));
            $rate = new ExchangeRate($currency, $functional, Row::text($item, 'rate'), Row::text($item, 'rate_as_of'),
                $currency == $functional ? 'identity' : 'write-off:'.$itemId, Rounding::from(Row::text($item, 'rounding')));
            $side = Side::from(Row::text($item, 'side'));
            $id = (string) Str::uuid7();
            $journal = $this->journals->post(new JournalRequest($bookId, 'write-off:'.hash('sha256', $key), $effectiveOn, $reason, [
                new Posting(Row::text($item, 'account_id'), $side->opposite(), $amount, $currency == $functional ? null : $rate, Row::text($item, 'party_id'), dimensions: Row::dimensions($item)),
                new Posting($accountId, $side, $rate->convert($amount), dimensions: Row::dimensions($item)),
            ], ['write_off_item_id' => $itemId], source: new Source('write_off', $id)));
            $newItem = $this->db->table('ledger_open_items')->where('book_id', $bookId)->where('journal_id', $journal->id)->where('line_no', 1)->value('id');
            Assert::that(is_string($newItem), 'Write-off did not create its settling item.');
            $allocation = $this->allocate(new AllocationRequest($bookId, 'write-off-allocation:'.hash('sha256', $key),
                $side === Side::Debit ? $itemId : $newItem, $side === Side::Debit ? $newItem : $itemId,
                $amount, $effectiveOn, $reason, $gainAccountId, $lossAccountId));
            $this->db->insert('ledger_write_offs', ['id' => $id, 'book_id' => $bookId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
                'item_id' => $itemId, 'account_id' => $accountId, 'amount_minor' => (string) $amount->minor, 'effective_on' => $effectiveOn,
                'journal_id' => $journal->id, 'allocation_id' => $allocation, 'reason' => $reason]);

            return $id;
        }, $bookId);
    }

    public function reinstate(string $bookId, string $writeOffId, string $key, string $effectiveOn, string $reason): string
    {
        Assert::text($key, 128);
        Assert::date($effectiveOn);
        Assert::text($reason, 1024);

        return $this->db->run(Permission::Allocate, function () use ($bookId, $writeOffId, $key, $effectiveOn, $reason): string {
            $this->db->lockBook($bookId);
            $fingerprint = Canonical::hash(['reinstate' => $writeOffId, 'effective_on' => $effectiveOn, 'reason' => $reason]);
            $existing = $this->db->table('ledger_write_offs')->where('book_id', $bookId)->where('idempotency_key', $key)->first();
            if ($existing instanceof stdClass) {
                Assert::that(hash_equals(Row::text($existing, 'fingerprint'), $fingerprint), 'Idempotency key was already used for a different write-off.');

                return Row::text($existing, 'id');
            }
            $original = $this->db->find('ledger_write_offs', $writeOffId, $bookId);
            Assert::that(Row::nullable($original, 'reverses_id') === null, 'A reinstatement cannot itself be reinstated.');
            $allocation = $this->release($bookId, Row::text($original, 'allocation_id'), 'reinstate-allocation:'.hash('sha256', $key), $effectiveOn, $reason);
            $journal = $this->journals->reverse($bookId, Row::text($original, 'journal_id'), 'reinstate:'.hash('sha256', $key), $effectiveOn, $reason);
            $id = (string) Str::uuid7();
            $this->db->insert('ledger_write_offs', ['id' => $id, 'book_id' => $bookId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
                'item_id' => Row::text($original, 'item_id'), 'account_id' => Row::text($original, 'account_id'), 'amount_minor' => Row::text($original, 'amount_minor'),
                'effective_on' => $effectiveOn, 'journal_id' => $journal->id, 'allocation_id' => $allocation, 'reverses_id' => $writeOffId, 'reason' => $reason]);

            return $id;
        }, $bookId);
    }

    /** @param array<string, string> $matrix */
    public function provide(string $bookId, string $key, string $controlAccountId, string $allowanceAccountId, string $expenseAccountId,
        string $effectiveOn, array $matrix, string $reason): string
    {
        Assert::text($key, 128);
        Assert::date($effectiveOn);
        Assert::text($reason, 1024);
        Assert::that($matrix !== [] && array_diff(array_keys($matrix), self::AGING_BUCKETS) === [], 'Allowance matrix buckets must be current, 1-30, 31-60, 61-90 or 91+.');
        foreach ($matrix as $rate) {
            Assert::that(BigRational::of(Assert::decimal($rate))->isGreaterThanOrEqualTo(0) && BigRational::of($rate)->isLessThanOrEqualTo(1), 'Allowance rates must be between 0 and 1.');
        }
        Assert::that($this->reports !== null, 'Allowances require the reports service.');

        return $this->db->run(Permission::Allocate, function () use ($bookId, $key, $controlAccountId, $allowanceAccountId, $expenseAccountId, $effectiveOn, $matrix, $reason): string {
            $book = $this->db->lockBook($bookId);
            $fingerprint = Canonical::hash([$controlAccountId, $allowanceAccountId, $expenseAccountId, $effectiveOn, $matrix, $reason]);
            $existing = $this->db->table('ledger_allowance_runs')->where('book_id', $bookId)->where('idempotency_key', $key)->first();
            if ($existing instanceof stdClass) {
                Assert::that(hash_equals(Row::text($existing, 'fingerprint'), $fingerprint), 'Idempotency key was already used for a different allowance.');

                return Row::text($existing, 'id');
            }
            $functional = $this->db->currency(Row::text($book, 'currency_code'));
            $exposure = array_fill_keys(array_keys($matrix), BigInteger::zero());
            $asOf = new \DateTimeImmutable($effectiveOn);
            $reports = $this->reports ?? throw new \LogicException('Missing reports service.');
            foreach ($reports->openItems(new Snapshot($bookId, $effectiveOn, Row::text($book, 'next_sequence'))) as $item) {
                if ($item->accountId !== $controlAccountId || $item->side !== Side::Debit) {
                    continue;
                }
                $days = (int) (new \DateTimeImmutable($item->dueOn))->diff($asOf)->format('%r%a');
                $bucket = match (true) {
                    $days <= 0 => 'current', $days <= 30 => '1-30', $days <= 60 => '31-60', $days <= 90 => '61-90', default => '91+',
                };
                if (isset($exposure[$bucket])) {
                    $exposure[$bucket] = $exposure[$bucket]->plus($item->functionalOutstanding->minor);
                }
            }
            $weighted = BigRational::zero();
            foreach ($matrix as $bucket => $rate) {
                $weighted = $weighted->plus(BigRational::of($rate)->multipliedBy($exposure[$bucket]));
            }
            $required = $weighted->toScale(0, Rounding::HalfEven->brick())->toBigInteger();
            $previous = $this->db->connection->table('ledger_postings AS p')->join('ledger_journals AS j', fn (JoinClause $join) => $join->on('j.tenant_id', '=', 'p.tenant_id')
                ->on('j.book_id', '=', 'p.book_id')->on('j.id', '=', 'p.journal_id'))->where('p.tenant_id', $this->db->scope->tenantId)->where('p.book_id', $bookId)
                ->where('p.account_id', Assert::uuid($allowanceAccountId))->where('j.effective_on', '<=', $effectiveOn)
                ->selectRaw("coalesce(sum(CASE WHEN p.side = 'credit' THEN p.functional_minor ELSE -p.functional_minor END), 0)::text AS balance")->value('balance');
            $previous = BigInteger::of(is_string($previous) ? $previous : '0');
            $difference = $required->minus($previous);
            $id = (string) Str::uuid7();
            $journalId = null;
            if (! $difference->isZero()) {
                $money = Money::minor((string) $difference->abs(), $functional);
                $journalId = $this->journals->post(new JournalRequest($bookId, 'allowance:'.hash('sha256', $key), $effectiveOn, $reason, [
                    new Posting($allowanceAccountId, $difference->isPositive() ? Side::Credit : Side::Debit, $money),
                    new Posting($expenseAccountId, $difference->isPositive() ? Side::Debit : Side::Credit, $money),
                ], ['allowance_run_id' => $id], source: new Source('allowance', $id)))->id;
            }
            $this->db->insert('ledger_allowance_runs', ['id' => $id, 'book_id' => $bookId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
                'control_account_id' => $controlAccountId, 'allowance_account_id' => $allowanceAccountId, 'expense_account_id' => $expenseAccountId,
                'effective_on' => $effectiveOn, 'matrix' => Canonical::json($matrix), 'exposure' => Canonical::json(array_map(fn (BigInteger $minor): string => (string) $minor, $exposure)),
                'required_minor' => (string) $required, 'previous_minor' => (string) $previous, 'journal_id' => $journalId, 'reason' => $reason]);

            return $id;
        }, $bookId);
    }

    /** @param list<string> $itemIds */
    private function clearRevaluations(string $bookId, array $itemIds, string $key, string $effectiveOn): void
    {
        foreach ($itemIds as $itemId) {
            $this->revaluations->clearForSettlement($bookId, $itemId, $key, $effectiveOn);
        }
    }

    private function item(string $bookId, string $id): stdClass
    {
        $item = $this->db->find('ledger_open_items', $id, $bookId);
        $row = $this->db->table('ledger_postings')->where('book_id', $bookId)->where('journal_id', Row::text($item, 'journal_id'))->where('line_no', Row::text($item, 'line_no'))->first();
        Assert::that($row instanceof stdClass, 'Open item posting is missing.');

        return $row;
    }

    private function functionalShare(string $bookId, string $itemId, stdClass $item, BigInteger $amount, Side $side): BigInteger
    {
        $column = $side === Side::Debit ? 'debit_item_id' : 'credit_item_id';
        $functionalColumn = $side === Side::Debit ? 'debit_functional_minor' : 'credit_functional_minor';
        $totals = $this->db->table('ledger_allocations')->where('book_id', $bookId)->where($column, $itemId)
            ->selectRaw('coalesce(sum(CASE WHEN reverses_id IS NULL THEN amount_minor ELSE -amount_minor END), 0)::text AS amount')
            ->selectRaw('coalesce(sum(CASE WHEN reverses_id IS NULL THEN '.$functionalColumn.' ELSE -'.$functionalColumn.' END), 0)::text AS functional')->first();
        $already = BigInteger::of($totals instanceof stdClass ? Row::text($totals, 'amount') : '0');
        $functional = BigInteger::of($totals instanceof stdClass ? Row::text($totals, 'functional') : '0');
        Assert::that($already->plus($amount)->isLessThanOrEqualTo(Row::text($item, 'amount_minor')), 'Allocation exceeds the outstanding amount.');

        return BigRational::of($amount)->multipliedBy(BigInteger::of(Row::text($item, 'functional_minor'))->minus($functional))
            ->dividedBy(BigInteger::of(Row::text($item, 'amount_minor'))->minus($already))
            ->toScale(0, Rounding::HalfEven->brick())->toBigInteger();
    }

    private function replay(string $bookId, string $key, string $fingerprint): ?string
    {
        $row = $this->db->table('ledger_allocations')->where('book_id', $bookId)->where('idempotency_key', $key)->first();

        if (! $row instanceof stdClass) {
            return null;
        }

        Assert::that(hash_equals(Row::text($row, 'fingerprint'), $fingerprint), 'Idempotency key was already used for a different allocation.');

        return Row::text($row, 'id');
    }
}
