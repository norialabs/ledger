<?php

declare(strict_types=1);

namespace Noria\Ledger;

use Brick\Math\BigInteger;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\Journal;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Accounting\Source;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Reporting\Snapshot;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Canonical;
use Noria\Ledger\Support\Row;
use stdClass;

readonly class Finance
{
    public function __construct(private ScopedDatabase $db, private Journals $journals, private Configurations $configurations, private ?Schedules $schedules = null) {}

    /** @param array<string, mixed> $inputs
     * @param  array<string, string>  $dimensions
     */
    public function open(string $bookId, string $key, string $reference, string $effectiveOn, string $kind, string $configurationId, array $inputs, array $dimensions = []): string
    {
        Assert::text($key, 128);
        Assert::text($reference, 128);
        Assert::date($effectiveOn);
        Assert::that(in_array($kind, ['loan_asset', 'loan_liability', 'lease', 'revenue'], true), 'Unknown registered finance contract kind.');

        return $this->db->run(Permission::Finance, function () use ($bookId, $key, $reference, $effectiveOn, $kind, $configurationId, $inputs, $dimensions): string {
            $book = $this->db->lockBook($bookId);
            $fingerprint = Canonical::hash([$reference, $effectiveOn, $kind, $configurationId, $inputs, $dimensions]);
            $existing = $this->db->table('ledger_finance_contracts')->where('book_id', $bookId)->where('idempotency_key', $key)->first();
            if ($existing instanceof stdClass) {
                Assert::that(Row::text($existing, 'fingerprint') === $fingerprint, 'Finance registration key already contains different inputs.');

                return Row::text($existing, 'id');
            }
            $configuration = $this->configurations->get($bookId, $configurationId, $effectiveOn);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $row = $this->db->connection->selectOne('SELECT ledger_finance_initial(?, ?::jsonb, ?::jsonb, ?::integer, ?::date)::text AS result',
                [$kind, Canonical::json($inputs), Canonical::json($configuration['roles']), $currency->scale, $effectiveOn]);
            $quote = $this->quoteRow($row);
            $id = (string) Str::uuid7();
            $journal = $this->post($bookId, 'finance-open:'.hash('sha256', $key), $effectiveOn, 'Recognize '.$reference, $quote, $currency, $dimensions, $id);
            Assert::that($journal !== null, 'Finance recognition must have a journal.');
            $this->db->insert('ledger_finance_contracts', ['id' => $id, 'book_id' => $bookId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
                'reference' => $reference, 'effective_on' => $effectiveOn, 'kind' => $kind, 'configuration_id' => $configurationId,
                'inputs' => Canonical::json($inputs), 'dimensions' => $dimensions === [] ? '{}' : Canonical::json($dimensions),
                'carrying_account_id' => Row::text($quote, 'carrying_account_id'), 'allowance_account_id' => Row::nullable($quote, 'allowance_account_id'),
                'asset_account_id' => Row::nullable($quote, 'asset_account_id'), 'opening_minor' => Row::text($quote, 'opening_minor'), 'journal_id' => $journal->id]);

            return $id;
        }, $bookId);
    }

    /** @param array<string, mixed> $evidence */
    public function period(string $bookId, string $contractId, string $key, string $effectiveOn, int $line, string $reason, array $evidence = [],
        ?string $planId = null, ?string $paidAmount = null, ?string $interestPaid = null): string
    {
        $inputs = ['line' => $line, 'evidence' => $evidence];
        if ($planId !== null) {
            $inputs['plan_id'] = Assert::uuid($planId);
        }
        Assert::that(($paidAmount === null) === ($interestPaid === null), 'Actual loan cash requires its explicit interest allocation.');
        if ($paidAmount !== null && $interestPaid !== null) {
            $inputs['paid_amount'] = Assert::decimal($paidAmount);
            $inputs['interest_paid'] = Assert::decimal($interestPaid);
        }

        return $this->event($bookId, $contractId, $key, $effectiveOn, 'period', $inputs, $reason);
    }

    /** @param non-empty-list<array{due_on: string, payment: string, interest_paid: string}> $payments
     * @param  array<string, mixed>|null  $lossModel
     * @param  array<string, mixed>  $evidence
     */
    public function modify(string $bookId, string $contractId, string $key, string $effectiveOn, array $payments, string $gainAccountId, string $lossAccountId,
        string $basisReference, string $reason, ?array $lossModel = null, array $evidence = []): string
    {
        Assert::uuid($gainAccountId);
        Assert::uuid($lossAccountId);
        Assert::text($basisReference, 256);
        $inputs = ['payments' => $payments, 'gain_account_id' => $gainAccountId, 'loss_account_id' => $lossAccountId, 'basis_reference' => $basisReference, 'evidence' => $evidence];
        if ($lossModel !== null) {
            $inputs['loss_model'] = $lossModel;
        }

        return $this->event($bookId, $contractId, $key, $effectiveOn, 'modification', $inputs, $reason);
    }

    /** @param list<array{due_on: string, payment: string, interest_paid?: string}> $payments
     * @param  array<string, mixed>|null  $lossModel
     * @param  array<string, mixed>  $evidence
     */
    public function prepay(string $bookId, string $contractId, string $key, string $effectiveOn, string $amount, string $interestPaid, array $payments,
        string $gainAccountId, string $lossAccountId, string $basisReference, string $reason, ?array $lossModel = null, array $evidence = []): string
    {
        Assert::uuid($gainAccountId);
        Assert::uuid($lossAccountId);
        Assert::text($basisReference, 256);
        $inputs = ['payments' => $payments, 'gain_account_id' => $gainAccountId, 'loss_account_id' => $lossAccountId, 'basis_reference' => $basisReference,
            'prepayment' => Assert::decimal($amount), 'interest_paid' => Assert::decimal($interestPaid), 'evidence' => $evidence];
        if ($lossModel !== null) {
            $inputs['loss_model'] = $lossModel;
        }

        return $this->event($bookId, $contractId, $key, $effectiveOn, 'modification', $inputs, $reason);
    }

    /** @param list<array{due_on: string, payment: string, interest_paid?: string}> $payments
     * @param  array<string, mixed>  $evidence
     */
    public function remeasureLease(string $bookId, string $contractId, string $key, string $effectiveOn, array $payments, string $gainAccountId, string $lossAccountId,
        string $rightOfUseCarrying, string $basisReference, string $reason, array $evidence = []): string
    {
        Assert::uuid($gainAccountId);
        Assert::uuid($lossAccountId);
        Assert::text($basisReference, 256);

        return $this->event($bookId, $contractId, $key, $effectiveOn, 'modification', ['payments' => $payments, 'gain_account_id' => $gainAccountId, 'loss_account_id' => $lossAccountId,
            'rou_carrying' => Assert::decimal($rightOfUseCarrying), 'basis_reference' => $basisReference, 'evidence' => $evidence], $reason);
    }

    /** @param array<string, mixed> $evidence */
    public function partialWriteOff(string $bookId, string $contractId, string $key, string $effectiveOn, string $amount, string $reason, array $evidence = []): string
    {
        return $this->event($bookId, $contractId, $key, $effectiveOn, 'partial_writeoff', ['amount' => Assert::decimal($amount), 'evidence' => $evidence], $reason);
    }

    public function accrueInterest(string $bookId, string $contractId, string $key, string $date, string $accruedAccountId, string $reason): ?Journal
    {
        Assert::date($date);
        Assert::that($this->schedules !== null, 'Interest accruals require the schedules service.');

        return $this->db->run(Permission::Finance, function () use ($bookId, $contractId, $key, $date, $accruedAccountId, $reason): ?Journal {
            $book = $this->db->lockBook($bookId);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $contract = $this->db->find('ledger_finance_contracts', $contractId, $bookId);
            $kind = Row::text($contract, 'kind');
            Assert::that(in_array($kind, ['loan_asset', 'loan_liability', 'lease'], true), 'Only loans and leases accrue interest.');
            $snapshot = new Snapshot($bookId, $date, Row::text($book, 'next_sequence'));
            $position = $this->position($snapshot, $contractId);
            $terms = $this->plan($snapshot, $contractId);
            $payments = is_array($terms['payments'] ?? null) ? $terms['payments'] : [];
            $nextLine = is_int($terms['next_line'] ?? null) ? $terms['next_line'] : 0;
            $next = $payments[$nextLine - 1] ?? null;
            Assert::that(is_array($next) && is_string($next['due_on'] ?? null) && is_string($terms['carrying_on'] ?? null), 'No unpaid payment slot remains to accrue.');
            $from = new DateTimeImmutable(is_string($terms['carrying_on'] ?? null) ? $terms['carrying_on'] : $date);
            $due = new DateTimeImmutable($next['due_on']);
            $elapsed = (int) $from->diff(new DateTimeImmutable($date))->format('%r%a');
            $slot = (int) $from->diff($due)->format('%r%a');
            Assert::that($elapsed > 0 && $elapsed < $slot, 'Accrue only strictly between the last carrying date and the next due date.');
            $rate = is_string($terms['rate_per_slot'] ?? null) ? $terms['rate_per_slot'] : '0';
            $base = $kind === 'loan_asset' && $position['stage'] === 3 && $position['allowance']->minor->isPositive() ? $position['net'] : $position['carrying'];
            $interest = BigRational::of($base->minor)->multipliedBy($rate)->toScale(0, RoundingMode::HalfEven)->toBigInteger();
            $accrued = BigRational::of($interest)->multipliedBy($elapsed)->dividedBy($slot)->toScale(0, RoundingMode::HalfEven)->toBigInteger();
            if ($accrued->isZero()) {
                return null;
            }
            $configuration = $this->db->find('ledger_configurations', Row::text($contract, 'configuration_id'), $bookId);
            $interestAccount = Row::jsonObject($configuration, 'roles')['interest'] ?? null;
            Assert::that(is_string($interestAccount), 'The finance configuration has no interest role.');
            $amount = Money::minor((string) $accrued, $currency);
            $dimensions = Row::dimensions($contract);
            $asset = $kind === 'loan_asset';
            $schedules = $this->schedules ?? throw new \LogicException('Missing schedules service.');

            return $schedules->accrue($bookId, $key, $date, $reason, [
                new Posting($asset ? $accruedAccountId : $interestAccount, Side::Debit, $amount, dimensions: $dimensions),
                new Posting($asset ? $interestAccount : $accruedAccountId, Side::Credit, $amount, dimensions: $dimensions),
            ], metadata: ['finance_contract_id' => $contractId, 'accrual' => ['slot_interest_minor' => (string) $interest, 'elapsed_days' => $elapsed, 'slot_days' => $slot]]);
        }, $bookId);
    }

    /** @param array<string, mixed> $evidence */
    public function settle(string $bookId, string $contractId, string $key, string $effectiveOn, string $amount, string $interestPaid, string $gainAccountId, string $lossAccountId,
        string $basisReference, string $reason, array $evidence = []): string
    {
        Assert::uuid($gainAccountId);
        Assert::uuid($lossAccountId);
        Assert::text($basisReference, 256);

        return $this->event($bookId, $contractId, $key, $effectiveOn, 'settlement', ['amount' => Assert::decimal($amount), 'interest_paid' => Assert::decimal($interestPaid),
            'gain_account_id' => $gainAccountId, 'loss_account_id' => $lossAccountId, 'basis_reference' => $basisReference, 'evidence' => $evidence], $reason);
    }

    /** @return array<string, mixed> */
    public function plan(Snapshot $snapshot, string $contractId): array
    {
        return $this->db->run(Permission::Read, function () use ($snapshot, $contractId): array {
            $this->db->snapshotBook($snapshot);
            $this->db->find('ledger_finance_contracts', $contractId, $snapshot->bookId);
            $row = $this->db->connection->selectOne('SELECT ledger_finance_terms(?::uuid, ?::uuid, ?::uuid, ?::date, ?::bigint)::text AS result',
                [$this->db->scope->tenantId, $snapshot->bookId, $contractId, $snapshot->effectiveOn, $snapshot->throughSequence]);

            return Row::jsonObject($this->quoteResultRow($row), 'result');
        }, $snapshot->bookId);
    }

    /** @param array<string, mixed> $model */
    public function measureLoss(string $bookId, string $contractId, string $key, string $effectiveOn, array $model, string $reason): string
    {
        return $this->event($bookId, $contractId, $key, $effectiveOn, 'ecl', $model, $reason);
    }

    /** @param array<string, mixed> $evidence */
    public function recognize(string $bookId, string $contractId, string $key, string $effectiveOn, string $obligation, string $cumulativeFraction, string $reason, array $evidence = []): string
    {
        Assert::decimal($cumulativeFraction);

        return $this->event($bookId, $contractId, $key, $effectiveOn, 'recognize', ['reference' => $obligation, 'cumulative_fraction' => $cumulativeFraction, 'evidence' => $evidence], $reason);
    }

    /** @param array<string, mixed> $evidence */
    public function writeOff(string $bookId, string $contractId, string $key, string $effectiveOn, string $reason, array $evidence = []): string
    {
        return $this->event($bookId, $contractId, $key, $effectiveOn, 'writeoff', ['evidence' => $evidence], $reason);
    }

    /** @param array<string, mixed> $evidence */
    public function recover(string $bookId, string $contractId, string $key, string $effectiveOn, string $amount, string $reason, array $evidence = []): string
    {
        Assert::decimal($amount);

        return $this->event($bookId, $contractId, $key, $effectiveOn, 'recovery', ['amount' => $amount, 'evidence' => $evidence], $reason);
    }

    public function cancel(string $bookId, string $contractId, string $key, string $effectiveOn, string $reason): string
    {
        return $this->event($bookId, $contractId, $key, $effectiveOn, 'cancellation', [], $reason);
    }

    /** @return array{carrying: Money, allowance: Money, net: Money, written_off: Money, recovered: Money, stage: int, cancelled: bool, settled: bool} */
    public function position(Snapshot $snapshot, string $contractId): array
    {
        return $this->db->run(Permission::Read, function () use ($snapshot, $contractId): array {
            $book = $this->db->snapshotBook($snapshot);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $this->db->find('ledger_finance_contracts', $contractId, $snapshot->bookId);
            $row = $this->db->connection->selectOne('SELECT ledger_finance_position(?::uuid, ?::uuid, ?::uuid, ?::date, ?::bigint)::text AS result',
                [$this->db->scope->tenantId, $snapshot->bookId, $contractId, $snapshot->effectiveOn, $snapshot->throughSequence]);
            $quote = $this->quoteRow($row);
            $carry = Money::minor(Row::text($quote, 'carrying_minor'), $currency);
            $allowance = Money::minor(Row::text($quote, 'allowance_minor'), $currency);

            return ['carrying' => $carry, 'allowance' => $allowance, 'net' => $carry->minus($allowance),
                'written_off' => Money::minor(Row::text($quote, 'written_off_minor'), $currency), 'recovered' => Money::minor(Row::text($quote, 'recovered_minor'), $currency),
                'stage' => (int) Row::text($quote, 'stage'), 'cancelled' => Row::boolean($quote, 'cancelled'), 'settled' => Row::boolean($quote, 'settled')];
        }, $snapshot->bookId);
    }

    /** @return array{definition: stdClass, events: list<stdClass>} */
    public function evidence(Snapshot $snapshot, string $contractId, int $limit = 500, string $afterSequence = '0'): array
    {
        Assert::that($limit >= 1 && $limit <= 10000 && preg_match('/^[0-9]{1,19}$/D', $afterSequence) === 1
            && BigInteger::of($afterSequence)->isLessThanOrEqualTo('9223372036854775807'), 'Invalid finance evidence cursor.');

        return $this->db->run(Permission::Read, function () use ($snapshot, $contractId, $limit, $afterSequence): array {
            $this->db->snapshotBook($snapshot);
            $definition = $this->db->find('ledger_finance_contracts', $contractId, $snapshot->bookId);
            Assert::that(Row::text($definition, 'effective_on') <= $snapshot->effectiveOn && BigInteger::of(Row::text($definition, 'sequence'))->isLessThanOrEqualTo($snapshot->throughSequence), 'Finance contract was not known at this snapshot.');

            return ['definition' => $definition, 'events' => array_values($this->db->table('ledger_finance_events')->where('book_id', $snapshot->bookId)->where('contract_id', $contractId)
                ->where('sequence', '<=', $snapshot->throughSequence)->where('sequence', '>', $afterSequence)->where('effective_on', '<=', $snapshot->effectiveOn)->orderBy('sequence')->limit($limit)->get()->all())];
        }, $snapshot->bookId);
    }

    public function reverse(string $bookId, string $eventId, string $key, string $effectiveOn, string $reason): string
    {
        Assert::text($key, 128);
        Assert::date($effectiveOn);
        Assert::text($reason, 1024);

        return $this->db->run(Permission::Finance, function () use ($bookId, $eventId, $key, $effectiveOn, $reason): string {
            $this->db->lockBook($bookId);
            $fingerprint = Canonical::hash(['reverse', $eventId, $effectiveOn, $reason]);
            $replay = $this->replay($bookId, $key, $fingerprint);
            if ($replay !== null) {
                return $replay;
            }
            $original = $this->db->find('ledger_finance_events', $eventId, $bookId);
            $journalId = Row::nullable($original, 'journal_id');
            $journal = $journalId === null ? null : $this->journals->reverse($bookId, $journalId, 'finance-reverse:'.hash('sha256', $key), $effectiveOn, $reason);
            $values = [];
            foreach (['carrying_delta', 'allowance_delta', 'written_off_delta', 'recovered_delta'] as $field) {
                $values[$field] = (string) BigInteger::of(Row::text($original, $field))->negated();
            }
            $id = (string) Str::uuid7();
            $this->db->insert('ledger_finance_events', $values + ['id' => $id, 'book_id' => $bookId, 'contract_id' => Row::text($original, 'contract_id'),
                'idempotency_key' => $key, 'fingerprint' => $fingerprint, 'effective_on' => $effectiveOn, 'kind' => Row::text($original, 'kind'),
                'inputs' => Row::text($original, 'inputs'), 'stage' => Row::text($original, 'prior_stage'), 'prior_stage' => Row::text($original, 'stage'),
                'journal_id' => $journal?->id, 'reverses_id' => $eventId, 'reason' => $reason]);

            return $id;
        }, $bookId);
    }

    /** @param array<string, mixed> $inputs */
    private function event(string $bookId, string $contractId, string $key, string $effectiveOn, string $kind, array $inputs, string $reason): string
    {
        Assert::text($key, 128);
        Assert::date($effectiveOn);
        Assert::text($reason, 1024);

        return $this->db->run(Permission::Finance, function () use ($bookId, $contractId, $key, $effectiveOn, $kind, $inputs, $reason): string {
            $book = $this->db->lockBook($bookId);
            $fingerprint = Canonical::hash([$contractId, $effectiveOn, $kind, $inputs, $reason]);
            $replay = $this->replay($bookId, $key, $fingerprint);
            if ($replay !== null) {
                return $replay;
            }
            $definition = $this->db->find('ledger_finance_contracts', $contractId, $bookId);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $row = $this->db->connection->selectOne('SELECT ledger_finance_quote(?::uuid, ?::uuid, ?::uuid, ?::date, ?, ?::jsonb)::text AS result',
                [$this->db->scope->tenantId, $bookId, $contractId, $effectiveOn, $kind, $inputs === [] ? '{}' : Canonical::json($inputs)]);
            $quote = $this->quoteRow($row);
            $dimensions = Row::dimensions($definition);
            $id = (string) Str::uuid7();
            $journal = $kind === 'cancellation'
                ? $this->journals->reverse($bookId, Row::text($definition, 'journal_id'), 'finance-cancel:'.hash('sha256', $key), $effectiveOn, $reason)
                : $this->post($bookId, 'finance-event:'.hash('sha256', $key), $effectiveOn, $reason, $quote, $currency, $dimensions, $id);
            $values = [];
            foreach (['carrying_delta', 'allowance_delta', 'written_off_delta', 'recovered_delta', 'stage', 'prior_stage'] as $field) {
                $values[$field] = Row::text($quote, $field);
            }
            $this->db->insert('ledger_finance_events', $values + ['id' => $id, 'book_id' => $bookId, 'contract_id' => $contractId,
                'idempotency_key' => $key, 'fingerprint' => $fingerprint, 'effective_on' => $effectiveOn, 'kind' => $kind,
                'inputs' => $inputs === [] ? '{}' : Canonical::json($inputs), 'journal_id' => $journal?->id, 'reason' => $reason]);

            return $id;
        }, $bookId);
    }

    /** @param array<string, string> $dimensions */
    private function post(string $bookId, string $key, string $date, string $description, stdClass $quote, Currency $currency, array $dimensions, string $evidenceId): ?Journal
    {
        $rows = $quote->postings ?? null;
        Assert::that(is_array($rows), 'Invalid finance posting quote.');
        $postings = [];
        foreach ($rows as $row) {
            Assert::that(is_array($row), 'Invalid finance posting line.');
            $line = (object) $row;
            $postings[] = new Posting(Row::text($line, 'account_id'), Side::from(Row::text($line, 'side')), Money::minor(Row::text($line, 'amount_minor'), $currency), dimensions: $dimensions);
        }
        if ($postings === []) {
            return null;
        }
        $metadata = ['finance_evidence_id' => $evidenceId];
        $calculation = $quote->calculation ?? null;
        if (is_array($calculation)) {
            $metadata['finance_calculation'] = $calculation;
        }
        $cashFlow = $quote->cash_flow ?? null;
        if (is_array($cashFlow)) {
            $metadata['cash_flow'] = $cashFlow;
        }

        return $this->journals->post(new JournalRequest($bookId, $key, $date, $description, $postings, $metadata, source: new Source('finance.register', $evidenceId)));
    }

    private function quoteRow(mixed $row): stdClass
    {
        return (object) Row::json($this->quoteResultRow($row), 'result');
    }

    private function quoteResultRow(mixed $row): stdClass
    {
        Assert::that($row instanceof stdClass, 'Finance quote is missing.');

        return $row;
    }

    private function replay(string $bookId, string $key, string $fingerprint): ?string
    {
        $row = $this->db->table('ledger_finance_events')->where('book_id', $bookId)->where('idempotency_key', $key)->first();
        if (! $row instanceof stdClass) {
            return null;
        }
        Assert::that(Row::text($row, 'fingerprint') === $fingerprint, 'Finance event key already contains different inputs.');

        return Row::text($row, 'id');
    }
}
