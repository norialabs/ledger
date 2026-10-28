<?php

declare(strict_types=1);

namespace Noria\Ledger\Finance;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Noria\Ledger\Access\Scope;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\PostingTreatment;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Accounting\TreatmentRequest;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Money\Rounding;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Canonical;
use Noria\Ledger\Support\Row;

final readonly class FinanceTreatment implements PostingTreatment
{
    public function __construct(private ScopedDatabase $db) {}

    public function name(): string
    {
        return 'ledger.finance';
    }

    public function version(): string
    {
        return '1';
    }

    public function prepare(Scope $scope, TreatmentRequest $request, array $accounts): JournalRequest
    {
        $input = (object) $request->inputs;
        $operation = Row::text($input, 'operation');
        if (str_starts_with($operation, 'lease.')) {
            Assert::that(is_bool($request->inputs['in_advance'] ?? null), 'Lease payment timing must be an explicit boolean.');
        }
        Assert::text(Row::text($input, 'policy_version'), 128);
        Assert::text(Row::text($input, 'basis_reference'), 256);
        $book = $this->db->find('ledger_books', $request->bookId);
        $currency = $this->db->currency(Row::text($book, 'currency_code'));
        $rounding = Rounding::from(Row::text($input, 'rounding'));
        $dimensions = [];
        $dimensionValues = $request->inputs['dimensions'] ?? [];
        Assert::that(is_array($dimensionValues), 'Finance dimensions must be a string map.');
        foreach ($dimensionValues as $key => $value) {
            Assert::that(is_string($key) && is_string($value), 'Finance dimensions must be a string map.');
            $dimensions[$key] = $value;
        }
        $postings = [];
        $evidence = [];
        $cashFlow = [];
        $add = function (string $role, string $type, Side $side, Money $amount) use ($request, $accounts, $currency, $dimensions, &$postings): void {
            Assert::that(isset($accounts[$role]), 'Missing finance account role: '.$role);
            $account = $this->db->find('ledger_accounts', $accounts[$role], $request->bookId);
            Assert::that(Row::text($account, 'type') === $type && ! Row::boolean($account, 'is_control') && ! Row::boolean($account, 'is_group')
                && (Row::nullable($account, 'currency_code') ?? $currency->code) === $currency->code, 'Finance roles require compatible functional non-control accounts: '.$role);
            Assert::that($role === 'cash' ? Row::nullable($account, 'cash_flow') === 'cash' : Row::nullable($account, 'cash_flow') !== 'cash', 'Finance cash classification is incompatible: '.$role);
            if (! $amount->minor->isZero()) {
                $postings[] = new Posting($accounts[$role], $amount->minor->isNegative() ? $side->opposite() : $side,
                    Money::minor((string) $amount->minor->abs(), $currency), dimensions: $dimensions);
            }
        };
        $calculator = new FinancialCalculator;
        if ($operation === 'loan.period' || $operation === 'lease.period') {
            if ($operation === 'lease.period') {
                $plan = $calculator->lease(Row::text($input, 'rate_per_slot'), $this->payments($request->inputs['payments'] ?? null, $currency), Row::boolean($input, 'in_advance'), $rounding);
                $rows = array_values(array_filter($plan['rows'], fn (array $row): bool => $row['due_on'] === $request->effectiveOn));
                Assert::that(count($rows) === 1, 'Lease period must select a due date from the measured plan.');
                $row = $rows[0];
                $payment = $row['payment'];
            } else {
                $opening = Money::of(Row::text($input, 'opening'), $currency);
                $payment = Money::of(Row::text($input, 'payment'), $currency);
                $row = $calculator->effectiveInterest($opening, Row::text($input, 'rate_per_slot'), [['due_on' => $request->effectiveOn, 'payment' => $payment]], $rounding)[0];
            }
            $lender = $operation === 'loan.period' && Row::text($input, 'position') === 'asset';
            Assert::that($operation !== 'loan.period' || in_array(Row::text($input, 'position'), ['asset', 'liability'], true), 'Loan position must be asset or liability.');
            $role = $operation === 'lease.period' ? 'lease.liability' : 'loan';
            $side = $lender ? Side::Debit : Side::Credit;
            Assert::that(isset($accounts[$role]), 'Missing finance carrying account role.');
            $carrying = $this->balance($request, $accounts[$role], $currency, $dimensions, ! $lender);
            Assert::that($row['opening']->minor->isEqualTo($carrying->minor), 'Period opening does not match the carrying-account dimension bucket.');
            $add($role, $lender ? 'asset' : 'liability', $side, $row['interest']);
            $add('interest', $lender ? 'income' : 'expense', $side->opposite(), $row['interest']);
            $add($role, $lender ? 'asset' : 'liability', $side->opposite(), $payment);
            $add('cash', 'asset', $side, $payment);
            $category = Row::text($input, 'interest_cash_flow');
            Assert::that(in_array($category, ['operating', 'investing', 'financing'], true), 'An explicit interest cash-flow classification is required.');
            $interestPaid = Money::of(Row::text($input, 'interest_paid'), $currency);
            Assert::that(! $interestPaid->minor->isNegative() && $interestPaid->minor->isLessThanOrEqualTo($payment->minor), 'Cash interest must be between zero and the payment.');
            $principalPaid = $payment->minus($interestPaid);
            $principalCategory = $lender ? 'investing' : 'financing';
            $sign = $lender ? '1' : '-1';
            $cashFlow[$principalCategory] = (string) $principalPaid->minor->multipliedBy($sign);
            $cashFlow[$category] = (string) $interestPaid->minor->multipliedBy($sign)->plus($cashFlow[$category] ?? '0');
            $evidence = ['row' => $row, 'interest_paid' => $interestPaid];
        } elseif ($operation === 'lease.commence') {
            $payments = $this->payments($request->inputs['payments'] ?? null, $currency);
            $advance = Row::boolean($input, 'in_advance');
            Assert::that($advance ? $payments[0]['due_on'] === $request->effectiveOn : $payments[0]['due_on'] > $request->effectiveOn, 'Lease payment timing conflicts with commencement.');
            $evidence = $calculator->lease(Row::text($input, 'rate_per_slot'), $payments, $advance, $rounding);
            $asset = $evidence['liability']->plus($evidence['commencement_payment']);
            $add('lease.asset', 'asset', Side::Debit, $asset);
            $add('lease.liability', 'liability', Side::Credit, $evidence['liability']);
            $add('cash', 'asset', Side::Credit, $evidence['commencement_payment']);
            $cashFlow = ['financing' => (string) $evidence['commencement_payment']->minor->negated()];
        } elseif ($operation === 'ecl.measure') {
            $contractual = [];
            $flows = $request->inputs['contractual'] ?? null;
            Assert::that(is_array($flows) && array_is_list($flows) && $flows !== [], 'ECL contractual cash flows must be a nonempty list.');
            foreach ($flows as $flow) {
                Assert::that(is_array($flow) && is_int($flow['period'] ?? null), 'ECL periods must be integers.');
                $contractual[] = ['period' => $flow['period'], 'amount' => Money::of(Row::text((object) $flow, 'amount'), $currency)];
            }
            $scenarios = [];
            $values = $request->inputs['scenarios'] ?? null;
            Assert::that(is_array($values) && array_is_list($values) && $values !== [], 'ECL scenarios must be a nonempty list.');
            foreach ($values as $value) {
                Assert::that(is_array($value) && is_array($value['receipts'] ?? null) && array_is_list($value['receipts']), 'Scenario receipts must be a list.');
                $receipts = [];
                foreach ($value['receipts'] as $receipt) {
                    Assert::that(is_string($receipt), 'Scenario receipts must be decimal strings.');
                    $receipts[] = Money::of($receipt, $currency);
                }
                $scenarios[] = ['weight' => Row::text((object) $value, 'weight'), 'receipts' => $receipts];
            }
            $evidence = $calculator->expectedCreditLoss(Row::text($input, 'rate_per_slot'), $contractual, $scenarios, $rounding);
            Assert::that(isset($accounts['ecl.allowance']), 'Missing ECL allowance role.');
            $previous = $this->balance($request, $accounts['ecl.allowance'], $currency, $dimensions, true);
            Assert::that(! $previous->minor->isNegative(), 'Previous allowance cannot be negative.');
            $delta = $evidence['allowance']->minus($previous);
            $add('ecl.expense', 'expense', Side::Debit, $delta);
            $add('ecl.allowance', 'asset', Side::Credit, $delta);
            $evidence['previous_allowance'] = $previous;
            $evidence['change'] = $delta;
        } elseif ($operation === 'revenue.recognize') {
            $contractReference = Assert::text(Row::text($input, 'contract_reference'), 128);
            $obligations = [];
            $values = $request->inputs['obligations'] ?? null;
            Assert::that(is_array($values) && array_is_list($values) && $values !== [], 'Revenue obligations must be a nonempty list.');
            foreach ($values as $value) {
                Assert::that(is_array($value), 'Invalid revenue obligation.');
                $row = (object) $value;
                $obligations[] = ['reference' => Row::text($row, 'reference'), 'standalone_price' => Row::text($row, 'standalone_price')];
            }
            $allocations = $calculator->allocateRevenue(Money::of(Row::text($input, 'transaction_price'), $currency), $obligations);
            $satisfied = $request->inputs['satisfied'] ?? null;
            Assert::that(is_array($satisfied) && array_is_list($satisfied) && $satisfied !== [], 'Select satisfied performance obligations explicitly.');
            $seen = [];
            $recognized = [];
            $prior = $this->db->table('ledger_journals')->where('book_id', $request->bookId)
                ->where('metadata->treatment->name', $this->name())->where('metadata->treatment->request->inputs->operation', 'revenue.recognize')
                ->where('metadata->treatment->request->inputs->contract_reference', $contractReference)
                ->whereNotExists(function (Builder $query): void {
                    $query->selectRaw('1')->from('ledger_journals AS reversal')->whereColumn('reversal.tenant_id', 'ledger_journals.tenant_id')
                        ->whereColumn('reversal.book_id', 'ledger_journals.book_id')->whereColumn('reversal.reverses_id', 'ledger_journals.id');
                })->get();
            foreach ($prior as $journal) {
                $metadata = Row::json($journal, 'metadata');
                $treatment = $metadata['treatment'] ?? null;
                $priorRequest = is_array($treatment) ? ($treatment['request'] ?? null) : null;
                $priorInputs = is_array($priorRequest) ? ($priorRequest['inputs'] ?? null) : null;
                $priorSatisfied = is_array($priorInputs) ? ($priorInputs['satisfied'] ?? null) : null;
                Assert::that(is_array($priorSatisfied), 'Invalid earlier contract recognition.');
                foreach ($priorSatisfied as $reference) {
                    if (is_string($reference)) {
                        $recognized[$reference] = true;
                    }
                }
                Assert::that(is_array($priorInputs) && Canonical::hash([$priorInputs['transaction_price'] ?? null, $priorInputs['obligations'] ?? null])
                    === Canonical::hash([$request->inputs['transaction_price'], $values]), 'Continuing recognition must preserve the contract allocation basis.');
                Assert::that(is_array($priorRequest) && ($priorRequest['configuration_id'] ?? null) === $request->configurationId
                    && Canonical::hash($priorInputs['dimensions'] ?? []) === Canonical::hash($dimensions), 'Continuing recognition must preserve its configuration and dimension bucket.');
            }
            $total = Money::minor('0', $currency);
            foreach ($satisfied as $reference) {
                Assert::that(is_string($reference) && isset($allocations[$reference]) && ! isset($seen[$reference]), 'Satisfied obligations must be unique known references.');
                Assert::that(! isset($recognized[$reference]), 'Performance obligation is already recognized.');
                $seen[$reference] = true;
                $total = $total->plus($allocations[$reference]);
                $add('revenue.'.$reference, 'income', Side::Credit, $allocations[$reference]);
            }
            Assert::that(isset($accounts['contract.liability']), 'Missing contract liability role.');
            $available = $this->balance($request, $accounts['contract.liability'], $currency, $dimensions, true);
            Assert::that($total->minor->isLessThanOrEqualTo($available->minor), 'Revenue recognition exceeds the contract liability bucket.');
            $add('contract.liability', 'liability', Side::Debit, $total);
            $evidence = ['allocations' => $allocations, 'recognized' => $total];
        } else {
            throw new LedgerException('Unknown finance operation.');
        }
        Assert::that(count($postings) >= 2, 'A zero-change finance calculation does not create a journal.');

        return new JournalRequest($request->bookId, $request->key, $request->effectiveOn, $operation.' '.$request->source->reference,
            $postings, ['finance' => $evidence, ...($cashFlow === [] ? [] : ['cash_flow' => $cashFlow])], source: $request->source);
    }

    /** @return non-empty-list<array{due_on: string, payment: Money}> */
    private function payments(mixed $values, Currency $currency): array
    {
        Assert::that(is_array($values) && array_is_list($values) && $values !== [], 'Payments must be a nonempty ordered list.');
        $payments = [];
        foreach ($values as $value) {
            Assert::that(is_array($value), 'Invalid payment input.');
            $row = (object) $value;
            $payments[] = ['due_on' => Row::text($row, 'due_on'), 'payment' => Money::of(Row::text($row, 'payment'), $currency)];
        }

        return $payments;
    }

    /** @param array<string, string> $dimensions */
    private function balance(TreatmentRequest $request, string $accountId, Currency $currency, array $dimensions, bool $creditPositive): Money
    {
        $row = $this->db->table('ledger_postings')->join('ledger_journals AS j', function (JoinClause $join): void {
            $join->on('j.tenant_id', '=', 'ledger_postings.tenant_id')->on('j.book_id', '=', 'ledger_postings.book_id')->on('j.id', '=', 'ledger_postings.journal_id');
        })->where('ledger_postings.book_id', $request->bookId)->where('ledger_postings.account_id', $accountId)
            ->whereRaw('ledger_postings.dimensions = ?::jsonb', [$dimensions === [] ? '{}' : Canonical::json($dimensions)])
            ->selectRaw("coalesce(sum(CASE WHEN ledger_postings.side = 'credit' THEN ledger_postings.functional_minor ELSE -ledger_postings.functional_minor END), 0)::text AS balance, max(j.effective_on)::text AS latest")->first();
        Assert::that($row instanceof \stdClass, 'Cannot read finance carrying balance.');
        Assert::that((Row::nullable($row, 'latest') ?? $request->effectiveOn) <= $request->effectiveOn, 'Finance measurements cannot precede carrying-account activity.');
        $balance = Money::minor(Row::text($row, 'balance'), $currency);

        return $creditPositive ? $balance : $balance->negated();
    }
}
