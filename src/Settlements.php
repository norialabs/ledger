<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger;

use Brick\Math\BigInteger;
use Brick\Math\BigRational;
use Illuminate\Support\Str;
use NoriaLabs\Ledger\Access\Permission;
use NoriaLabs\Ledger\Accounting\AccountType;
use NoriaLabs\Ledger\Accounting\AllocationRequest;
use NoriaLabs\Ledger\Accounting\JournalRequest;
use NoriaLabs\Ledger\Accounting\Posting;
use NoriaLabs\Ledger\Accounting\Side;
use NoriaLabs\Ledger\Money\ExchangeRate;
use NoriaLabs\Ledger\Money\Money;
use NoriaLabs\Ledger\Money\Rounding;
use NoriaLabs\Ledger\Storage\ScopedDatabase;
use NoriaLabs\Ledger\Support\Assert;
use NoriaLabs\Ledger\Support\Canonical;
use NoriaLabs\Ledger\Support\Row;
use stdClass;

final readonly class Settlements
{
    public function __construct(private ScopedDatabase $db, private Journals $journals, private Revaluations $revaluations) {}

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
            $id = (string) Str::uuid();
            $fxId = null;
            $difference = $creditFunctional->minus($debitFunctional);

            if (! $difference->isZero()) {
                $gain = $difference->isPositive();
                $accountId = $gain ? $request->gainAccountId : $request->lossAccountId;
                Assert::that($accountId !== null, 'Settlement requires an exchange gain or loss account.');
                $account = $this->db->find('ledger_accounts', $accountId ?? '', $request->bookId);
                Assert::that(Row::text($account, 'type') === ($gain ? AccountType::Income : AccountType::Expense)->value, 'Incorrect exchange gain or loss account type.');
                $functionalCurrency = $this->db->currency(Row::text($book, 'currency_code'));
                $valuation = Money::minor((string) $difference->abs(), $functionalCurrency);
                $rate = new ExchangeRate($currency, $functionalCurrency, Row::text($debit, 'rate'), Row::text($debit, 'rate_as_of'), Row::text($debit, 'rate_source'), Rounding::from(Row::text($debit, 'rounding')));
                $fxId = $this->journals->post(new JournalRequest($request->bookId, 'fx:'.hash('sha256', $request->key), $request->effectiveOn, 'Realized exchange difference', [
                    new Posting(Row::text($debit, 'account_id'), $gain ? Side::Debit : Side::Credit, Money::minor('0', $currency), $rate,
                        Row::text($debit, 'party_id'), dimensions: $this->dimensions($debit), valuation: $valuation),
                    new Posting($accountId ?? '', $gain ? Side::Credit : Side::Debit, $valuation, dimensions: $this->dimensions($debit)),
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
        Assert::text($reason, 1000);

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
            $id = (string) Str::uuid();
            $this->db->insert('ledger_allocations', ['id' => $id, 'book_id' => $bookId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
                'debit_item_id' => Row::text($original, 'debit_item_id'), 'credit_item_id' => Row::text($original, 'credit_item_id'),
                'amount_minor' => Row::text($original, 'amount_minor'), 'debit_functional_minor' => Row::text($original, 'debit_functional_minor'),
                'credit_functional_minor' => Row::text($original, 'credit_functional_minor'), 'effective_on' => $effectiveOn,
                'reverses_id' => $allocationId, 'fx_journal_id' => $reversal, 'reason' => $reason]);

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

        return $row instanceof stdClass ? $row : new stdClass;
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

    /** @return array<string, string> */
    private function dimensions(stdClass $row): array
    {
        $result = [];

        foreach (Row::json($row, 'dimensions') as $key => $value) {
            Assert::that(is_string($key) && is_string($value), 'Invalid stored dimensions.');

            if (is_string($key) && is_string($value)) {
                $result[$key] = $value;
            }
        }

        return $result;
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
