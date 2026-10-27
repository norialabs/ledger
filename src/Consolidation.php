<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger;

use Brick\Math\BigInteger;
use Brick\Math\BigRational;
use Illuminate\Support\Str;
use NoriaLabs\Ledger\Access\Permission;
use NoriaLabs\Ledger\Accounting\AccountType;
use NoriaLabs\Ledger\Consolidation\Member;
use NoriaLabs\Ledger\Money\Currency;
use NoriaLabs\Ledger\Money\ExchangeRate;
use NoriaLabs\Ledger\Money\Money;
use NoriaLabs\Ledger\Money\Rounding;
use NoriaLabs\Ledger\Reporting\Snapshot;
use NoriaLabs\Ledger\Storage\ScopedDatabase;
use NoriaLabs\Ledger\Support\Assert;
use NoriaLabs\Ledger\Support\Canonical;
use NoriaLabs\Ledger\Support\Row;
use stdClass;

final readonly class Consolidation
{
    public function __construct(private ScopedDatabase $db, private Reports $reports) {}

    /** @param non-empty-list<Member> $members */
    public function run(Snapshot $target, string $key, string $startsOn, array $members, string $translationReserveId, string $roundingReserveId, string $policyVersion): string
    {
        Assert::text($key, 128);
        Assert::text($policyVersion, 128);
        Assert::date($startsOn);
        Assert::that($startsOn <= $target->effectiveOn && $members !== [], 'Consolidation requires members and a valid reporting period.');
        Assert::that($translationReserveId !== $roundingReserveId, 'Translation and rounding adjustments require separate accounts.');

        return $this->db->run(Permission::Consolidate, function () use ($target, $key, $startsOn, $members, $translationReserveId, $roundingReserveId, $policyVersion): string {
            $bookIds = [$target->bookId];

            foreach ($members as $member) {
                Assert::that($member->snapshot->effectiveOn === $target->effectiveOn && ! in_array($member->snapshot->bookId, $bookIds, true), 'Each source book must be distinct and use the same report date.');
                $bookIds[] = $member->snapshot->bookId;
            }

            sort($bookIds, SORT_STRING);
            $books = [];

            foreach ($bookIds as $bookId) {
                $this->db->authorize(Permission::Read, $bookId);
                $books[$bookId] = $this->db->lockBook($bookId);
            }

            $fingerprint = Canonical::hash([$target, $startsOn, $members, $translationReserveId, $roundingReserveId, $policyVersion]);
            $existing = $this->db->table('ledger_consolidation_runs')->where('book_id', $target->bookId)->where('idempotency_key', $key)->first();

            if ($existing instanceof stdClass) {
                Assert::that(Row::text($existing, 'fingerprint') === $fingerprint, 'Consolidation key was already used with different inputs.');

                return Row::text($existing, 'id');
            }

            $currency = $this->db->currency(Row::text($books[$target->bookId], 'currency_code'));
            $accounts = [];
            $closing = [];
            $period = [];

            foreach ($this->db->table('ledger_accounts')->where('book_id', $target->bookId)->where('is_group', false)->get() as $account) {
                $id = Row::text($account, 'id');
                $accounts[$id] = $account;
                $closing[$id] = Money::minor('0', $currency);
                $period[$id] = Money::minor('0', $currency);
            }

            foreach ([$translationReserveId, $roundingReserveId] as $id) {
                Assert::that(isset($accounts[$id]) && Row::text($accounts[$id], 'type') === 'equity', 'Consolidation adjustments require equity posting accounts.');
            }

            $exact = BigRational::zero();
            $entities = [];
            $this->validateCutoff($target, $books[$target->bookId]);

            foreach ($this->reports->trialBalance($target) as $balance) {
                $closing[$balance->accountId] = $balance->closing;
                $exact = $exact->plus($balance->closing->rational());
            }

            foreach ($this->reports->trialBalance($target, $startsOn, includeClosing: false) as $balance) {
                $period[$balance->accountId] = $balance->debits->minus($balance->credits);
            }

            foreach ($members as $member) {
                $book = $books[$member->snapshot->bookId];
                $entity = Row::text($book, 'entity_id');
                Assert::that(! in_array($entity, $entities, true), 'Do not consolidate parallel books of the same entity twice.');
                $entities[] = $entity;
                $this->validateCutoff($member->snapshot, $book);
                $movements = [];

                foreach ($this->reports->trialBalance($member->snapshot, $startsOn, includeClosing: false) as $balance) {
                    $movements[$balance->accountId] = $balance->debits->minus($balance->credits);
                }

                foreach ($this->reports->trialBalance($member->snapshot) as $balance) {
                    $movement = $movements[$balance->accountId];

                    if ($balance->closing->minor->isZero() && $movement->minor->isZero()) {
                        continue;
                    }

                    $mapped = $member->accountMap[$balance->accountId] ?? '';
                    Assert::that(isset($accounts[$mapped]) && Row::text($accounts[$mapped], 'type') === $balance->type->value
                        && ! in_array($mapped, [$translationReserveId, $roundingReserveId], true), 'Every non-zero source account requires a compatible group account mapping.');
                    $historical = $member->historicalEquityAmounts[$balance->accountId] ?? null;

                    if ($historical !== null) {
                        Assert::that($balance->type === AccountType::Equity && $balance->closing->currency != $currency && $historical->currency == $currency,
                            'Historical translated balances apply only to foreign equity in the group currency.');
                        $rate = null;
                        $translated = $historical;
                        $translatedExact = $historical->rational();
                    } else {
                        $rate = $this->rate($member, $balance->accountId, $balance->type, $balance->closing->currency, $currency);
                        $translatedExact = $balance->closing->rational()->multipliedBy($rate->rate ?? '1');
                        $translated = $rate?->convert($balance->closing) ?? $balance->closing;
                    }
                    $closing[$mapped] = $closing[$mapped]->plus($translated);
                    $exact = $exact->plus($translatedExact);

                    if (in_array($balance->type, [AccountType::Income, AccountType::Expense], true)) {
                        $period[$mapped] = $period[$mapped]->plus($rate?->convert($movement) ?? $movement);
                    }
                }
            }

            $translation = Money::rounded($exact->negated(), $currency, Rounding::HalfEven);
            $closing[$translationReserveId] = $closing[$translationReserveId]->plus($translation);
            $sum = Money::minor('0', $currency);

            foreach ($closing as $value) {
                $sum = $sum->plus($value);
            }

            $rounding = $sum->negated();
            $closing[$roundingReserveId] = $closing[$roundingReserveId]->plus($rounding);
            $totals = array_fill_keys(['asset', 'liability', 'equity', 'income', 'expense'], Money::minor('0', $currency));
            $income = Money::minor('0', $currency);
            $expenses = Money::minor('0', $currency);
            $rows = [];

            foreach ($accounts as $id => $account) {
                $type = Row::text($account, 'type');
                $totals[$type] = $totals[$type]->plus($closing[$id]);
                $rows[] = ['account_id' => $id, 'code' => Row::text($account, 'code'), 'name' => Row::text($account, 'name'), 'type' => $type, 'closing' => $closing[$id]];

                if ($type === 'income') {
                    $income = $income->minus($period[$id]);
                } elseif ($type === 'expense') {
                    $expenses = $expenses->plus($period[$id]);
                }
            }

            $earnings = $totals['income']->plus($totals['expense'])->negated();
            $result = ['trial_balance' => $rows, 'income_statement' => ['income' => $income, 'expenses' => $expenses, 'profit' => $income->minus($expenses)],
                'balance_sheet' => ['assets' => $totals['asset'], 'liabilities' => $totals['liability']->negated(), 'equity' => $totals['equity']->negated(), 'unclosed_earnings' => $earnings,
                    'difference' => $totals['asset']->plus($totals['liability'])->plus($totals['equity'])->minus($earnings)],
                'translation_adjustment' => $translation, 'rounding_adjustment' => $rounding, 'source_snapshots' => array_map(fn (Member $member): Snapshot => $member->snapshot, $members),
                'elimination_snapshot' => $target, 'policy_version' => $policyVersion];
            $id = (string) Str::uuid();
            $this->db->insert('ledger_consolidation_runs', ['id' => $id, 'book_id' => $target->bookId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
                'effective_on' => $target->effectiveOn, 'inputs' => Canonical::json(['target' => $target, 'members' => $members, 'starts_on' => $startsOn,
                    'translation_reserve_id' => $translationReserveId, 'rounding_reserve_id' => $roundingReserveId, 'policy_version' => $policyVersion]), 'result' => Canonical::json($result)]);

            return $id;
        }, $target->bookId);
    }

    /** @return array<array-key, mixed> */
    public function get(string $bookId, string $reportId): array
    {
        return $this->db->run(Permission::Read, function () use ($bookId, $reportId): array {
            $report = $this->authorizedReport($bookId, $reportId);

            return Row::json($report, 'result');
        }, $bookId);
    }

    /** @return array<string, mixed> */
    public function evidence(string $bookId, string $reportId): array
    {
        return $this->db->run(Permission::Read, function () use ($bookId, $reportId): array {
            $report = $this->authorizedReport($bookId, $reportId);

            return ['id' => Row::text($report, 'id'), 'inputs' => Row::json($report, 'inputs'), 'result_json' => Row::text($report, 'result'),
                'result_hash' => Row::text($report, 'result_hash'), 'input_fingerprint' => Row::text($report, 'fingerprint'),
                'recorded_at' => Row::text($report, 'recorded_at'), 'actor_id' => Row::text($report, 'actor_id'), 'firm_id' => Row::nullable($report, 'firm_id'),
                'sequence' => Row::text($report, 'sequence')];
        }, $bookId);
    }

    private function authorizedReport(string $bookId, string $reportId): stdClass
    {
        $report = $this->db->find('ledger_consolidation_runs', $reportId, $bookId);
        $inputs = Row::json($report, 'inputs');
        $members = $inputs['members'] ?? [];

        if (is_array($members)) {
            foreach ($members as $member) {
                $row = (object) $member;
                $snapshot = (object) ($row->snapshot ?? []);
                $this->db->authorize(Permission::Read, Row::text($snapshot, 'book_id'));
            }
        }

        return $report;
    }

    private function rate(Member $member, string $accountId, AccountType $type, Currency $from, Currency $to): ?ExchangeRate
    {
        if ($from == $to) {
            return null;
        }

        $rate = match ($type) {
            AccountType::Asset, AccountType::Liability => $member->closingRate,
            AccountType::Income, AccountType::Expense => $member->incomeRate,
            AccountType::Equity => $member->historicalEquityRates[$accountId] ?? null,
        };
        Assert::that($rate instanceof ExchangeRate && $rate->from == $from && $rate->to == $to && $rate->asOf <= $member->snapshot->effectiveOn,
            'Foreign consolidation requires approved closing, income, and historical equity rates.');

        return $rate;
    }

    private function validateCutoff(Snapshot $snapshot, stdClass $book): void
    {
        Assert::that(BigInteger::of($snapshot->throughSequence)->isLessThanOrEqualTo(Row::text($book, 'next_sequence')), 'Consolidation cannot use a future knowledge cutoff.');
    }
}
