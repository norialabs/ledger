<?php

declare(strict_types=1);

namespace Noria\Ledger;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\BigRational;
use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\Journal;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Accounting\Source;
use Noria\Ledger\Associates\AssociatePosition;
use Noria\Ledger\Associates\AssociateRequest;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Money\Rounding;
use Noria\Ledger\Reporting\Snapshot;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Canonical;
use Noria\Ledger\Support\Row;
use stdClass;

readonly class Associates
{
    public function __construct(private ScopedDatabase $db, private Journals $journals) {}

    public function acquire(AssociateRequest $request): string
    {
        return $this->db->run(Permission::Investments, function () use ($request): string {
            $book = $this->db->lockBook($request->bookId);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            Assert::that($currency == $request->cost->currency, 'Associate measurements require investor functional currency.');
            $fingerprint = Canonical::hash($request);
            $existing = $this->db->table('ledger_associates')->where('book_id', $request->bookId)->where('idempotency_key', $request->key)->first();
            if ($existing instanceof stdClass) {
                Assert::that(Row::text($existing, 'fingerprint') === $fingerprint, 'Associate acquisition key already contains different inputs.');

                return Row::text($existing, 'id');
            }
            $residual = $request->cost->rational()->minus($request->fairValueNetAssets->rational()->multipliedBy($request->ownershipFraction));
            $goodwill = Money::rounded($residual->isPositive() ? $residual : BigRational::zero(), $currency, Rounding::HalfEven);
            $bargain = Money::rounded($residual->isNegative() ? $residual->negated() : BigRational::zero(), $currency, Rounding::HalfEven);
            $carrying = $request->cost->plus($bargain);
            $id = (string) Str::uuid7();
            $journal = $this->post($request->bookId, 'associate-acquire:'.hash('sha256', $request->key), $request->effectiveOn, 'Acquire '.$request->reference,
                [[$request->investmentAccountId, $carrying], [$request->fundingAccountId, $request->cost->negated()], [$request->profitAccountId, $bargain->negated()]],
                $id, $request->fundingAccountId, $request->cost->negated(), dimensions: $request->dimensions);
            $this->db->insert('ledger_associates', ['id' => $id, 'book_id' => $request->bookId, 'investee_entity_id' => $request->investeeEntityId, 'idempotency_key' => $request->key,
                'fingerprint' => $fingerprint, 'reference' => $request->reference, 'effective_on' => $request->effectiveOn, 'ownership_fraction' => $request->ownershipFraction,
                'cost_minor' => (string) $request->cost->minor, 'fair_value_net_assets_minor' => (string) $request->fairValueNetAssets->minor,
                'carrying_minor' => (string) $carrying->minor, 'goodwill_minor' => (string) $goodwill->minor, 'bargain_minor' => (string) $bargain->minor,
                'investment_account_id' => $request->investmentAccountId, 'funding_account_id' => $request->fundingAccountId, 'profit_account_id' => $request->profitAccountId,
                'oci_account_id' => $request->ociAccountId, 'dividend_account_id' => $request->dividendAccountId, 'impairment_account_id' => $request->impairmentAccountId,
                'loss_liability_account_id' => $request->lossLiabilityAccountId, 'influence_basis' => $request->influenceBasis, 'policy_version' => $request->policyVersion,
                'dividend_cash_flow' => $request->dividendCashFlow, 'dimensions' => $request->dimensions === [] ? '{}' : Canonical::json($request->dimensions), 'journal_id' => $journal?->id]);

            return $id;
        }, $request->bookId);
    }

    /** @param array<string, mixed> $evidence */
    public function profit(string $bookId, string $associateId, string $key, string $date, Money $investeeProfit, string $reason, array $evidence = []): string
    {
        return $this->event($bookId, $associateId, $key, $date, 'profit', $investeeProfit, $reason, $evidence);
    }

    /** @param array<string, mixed> $evidence */
    public function oci(string $bookId, string $associateId, string $key, string $date, Money $investeeOci, string $reason, array $evidence = []): string
    {
        return $this->event($bookId, $associateId, $key, $date, 'oci', $investeeOci, $reason, $evidence);
    }

    /** @param array<string, mixed> $evidence */
    public function dividend(string $bookId, string $associateId, string $key, string $date, Money $investeeDistribution, string $reason, array $evidence = []): string
    {
        return $this->event($bookId, $associateId, $key, $date, 'dividend', $investeeDistribution, $reason, $evidence);
    }

    /** @param array<string, mixed> $evidence */
    public function obligate(string $bookId, string $associateId, string $key, string $date, Money $lossObligationLimit, string $reason, array $evidence = []): string
    {
        return $this->event($bookId, $associateId, $key, $date, 'obligation', $lossObligationLimit, $reason, $evidence, $lossObligationLimit);
    }

    /** @param array<string, mixed> $evidence */
    public function settleObligation(string $bookId, string $associateId, string $key, string $date, Money $amount, string $counterAccountId, string $reason, array $evidence = []): string
    {
        return $this->event($bookId, $associateId, $key, $date, 'settlement', $amount, $reason, $evidence, counterAccountId: $counterAccountId);
    }

    /** @param array<string, mixed> $evidence */
    public function impair(string $bookId, string $associateId, string $key, string $date, Money $recoverableAmount, string $reason, array $evidence = []): string
    {
        return $this->event($bookId, $associateId, $key, $date, 'impairment', $recoverableAmount, $reason, $evidence);
    }

    /** @param array<string, mixed> $evidence */
    public function recover(string $bookId, string $associateId, string $key, string $date, Money $recoverableAmount, Money $unimpairedCarrying, string $reason, array $evidence = []): string
    {
        return $this->event($bookId, $associateId, $key, $date, 'recovery', $recoverableAmount, $reason, $evidence, unimpairedCarrying: $unimpairedCarrying);
    }

    /** @param array<string, mixed> $evidence */
    public function dispose(string $bookId, string $associateId, string $key, string $date, string $fraction, Money $proceeds, string $proceedsAccountId,
        string $gainAccountId, string $lossAccountId, string $reason, ?string $ociTransferAccountId = null, ?Money $retainedFairValue = null,
        ?string $retainedAccountId = null, array $evidence = []): string
    {
        $portion = BigDecimal::of(Assert::decimal($fraction));
        Assert::that($portion->isPositive() && $portion->isLessThanOrEqualTo(1) && $portion->getScale() <= 18, 'Disposal fraction must be greater than zero and at most one.');
        Assert::that(($retainedFairValue === null) === ($retainedAccountId === null), 'A retained interest needs both its fair value and account.');

        return $this->event($bookId, $associateId, $key, $date, 'disposal', $proceeds, $reason, $evidence, counterAccountId: $proceedsAccountId, terms: [
            'fraction' => (string) $portion->strippedOfTrailingZeros(), 'gain_account_id' => $gainAccountId, 'loss_account_id' => $lossAccountId,
            'oci_transfer_account_id' => $ociTransferAccountId, 'retained_account_id' => $retainedAccountId,
            'retained_fair_value_minor' => $retainedFairValue === null ? null : (string) $retainedFairValue->minor,
        ]);
    }

    /** @param array<string, mixed> $evidence */
    public function increase(string $bookId, string $associateId, string $key, string $date, string $additionalFraction, Money $cost, Money $fairValueNetAssets,
        string $fundingAccountId, string $reason, array $evidence = []): string
    {
        $portion = BigDecimal::of(Assert::decimal($additionalFraction));
        Assert::that($portion->isPositive() && $portion->isLessThan(1) && $portion->getScale() <= 18, 'The additional ownership fraction must be between zero and one.');
        Assert::that($cost->currency == $fairValueNetAssets->currency, 'Increase inputs use one currency.');

        return $this->event($bookId, $associateId, $key, $date, 'increase', $cost, $reason, $evidence, counterAccountId: $fundingAccountId,
            terms: ['fraction' => (string) $portion->strippedOfTrailingZeros(), 'net_assets_minor' => (string) $fairValueNetAssets->minor]);
    }

    /** @param array<string, mixed> $evidence */
    public function holdForSale(string $bookId, string $associateId, string $key, string $date, string $reason, array $evidence = []): string
    {
        return $this->db->run(Permission::Investments, function () use ($bookId, $associateId, $key, $date, $reason, $evidence): string {
            $book = $this->db->lockBook($bookId);

            return $this->event($bookId, $associateId, $key, $date, 'held_for_sale', Money::minor('0', $this->db->currency(Row::text($book, 'currency_code'))), $reason, $evidence);
        }, $bookId);
    }

    public function cancel(string $bookId, string $associateId, string $key, string $date, string $reason): string
    {
        return $this->db->run(Permission::Investments, function () use ($bookId, $associateId, $key, $date, $reason): string {
            $book = $this->db->lockBook($bookId);

            return $this->event($bookId, $associateId, $key, $date, 'cancellation', Money::minor('0', $this->db->currency(Row::text($book, 'currency_code'))), $reason, []);
        }, $bookId);
    }

    public function position(Snapshot $snapshot, string $associateId): AssociatePosition
    {
        return $this->db->run(Permission::Read, function () use ($snapshot, $associateId): AssociatePosition {
            $book = $this->db->snapshotBook($snapshot);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $this->db->find('ledger_associates', $associateId, $snapshot->bookId);
            $row = $this->db->connection->selectOne('SELECT ledger_associate_position(?::uuid, ?::uuid, ?::uuid, ?::date, ?::bigint)::text AS result', [$this->db->scope->tenantId, $snapshot->bookId, $associateId, $snapshot->effectiveOn, $snapshot->throughSequence]);
            Assert::that($row instanceof stdClass, 'Associate position is missing.');
            $state = (object) ($row instanceof stdClass ? Row::json($row, 'result') : []);

            return new AssociatePosition(Money::minor(Row::text($state, 'carrying_minor'), $currency), Money::minor(Row::text($state, 'liability_minor'), $currency),
                Money::minor(Row::text($state, 'unrecognized_profit_minor'), $currency), Money::minor(Row::text($state, 'unrecognized_oci_minor'), $currency),
                Money::minor(Row::text($state, 'impairment_minor'), $currency), Money::minor(Row::text($state, 'obligation_limit_minor'), $currency),
                Money::minor(Row::text($state, 'goodwill_minor'), $currency), Money::minor(Row::text($state, 'bargain_minor'), $currency), Row::boolean($state, 'cancelled'),
                (string) BigDecimal::of(Row::text($state, 'ownership_fraction'))->strippedOfTrailingZeros(), Row::boolean($state, 'disposed'), Money::minor(Row::text($state, 'oci_minor'), $currency), Row::boolean($state, 'held_for_sale'));
        }, $snapshot->bookId);
    }

    /** @return array{definition: stdClass, events: list<stdClass>} */
    public function evidence(Snapshot $snapshot, string $associateId, int $limit = 500, string $afterSequence = '0'): array
    {
        Assert::that($limit >= 1 && $limit <= 10000 && preg_match('/^[0-9]{1,19}$/D', $afterSequence) === 1, 'Invalid associate evidence page.');
        Assert::that(BigInteger::of($afterSequence)->isLessThanOrEqualTo('9223372036854775807'), 'Associate evidence cursor exceeds the database range.');

        return $this->db->run(Permission::Read, function () use ($snapshot, $associateId, $limit, $afterSequence): array {
            $this->db->snapshotBook($snapshot);
            $definition = $this->db->find('ledger_associates', $associateId, $snapshot->bookId);
            Assert::that(Row::text($definition, 'effective_on') <= $snapshot->effectiveOn && BigInteger::of(Row::text($definition, 'sequence'))->isLessThanOrEqualTo($snapshot->throughSequence), 'Associate was not known at this snapshot.');

            return ['definition' => $definition, 'events' => array_values($this->db->table('ledger_associate_events')->where('book_id', $snapshot->bookId)->where('associate_id', $associateId)
                ->where('sequence', '<=', $snapshot->throughSequence)->where('sequence', '>', $afterSequence)->where('effective_on', '<=', $snapshot->effectiveOn)->orderBy('sequence')->limit($limit)->get()->all())];
        }, $snapshot->bookId);
    }

    public function reverse(string $bookId, string $eventId, string $key, string $date, string $reason): string
    {
        Assert::text($key, 128);
        Assert::date($date);
        Assert::text($reason, 1024);

        return $this->db->run(Permission::Investments, function () use ($bookId, $eventId, $key, $date, $reason): string {
            $this->db->lockBook($bookId);
            $fingerprint = Canonical::hash(['reverse', $eventId, $date, $reason]);
            $replay = $this->replay($bookId, $key, $fingerprint);
            if ($replay !== null) {
                return $replay;
            }
            $original = $this->db->find('ledger_associate_events', $eventId, $bookId);
            $journalId = Row::nullable($original, 'journal_id');
            $journal = $journalId === null ? null : $this->journals->reverse($bookId, $journalId, 'associate-reverse:'.hash('sha256', $key), $date, $reason);
            $values = [];
            foreach (['source_minor', 'share_minor', 'carrying_delta', 'liability_delta', 'unrecognized_profit_delta', 'unrecognized_oci_delta', 'impairment_delta'] as $name) {
                $values[$name] = (string) BigInteger::of(Row::text($original, $name))->negated();
            }
            $id = (string) Str::uuid7();
            $this->db->insert('ledger_associate_events', $values + ['id' => $id, 'book_id' => $bookId, 'associate_id' => Row::text($original, 'associate_id'), 'idempotency_key' => $key,
                'fingerprint' => $fingerprint, 'effective_on' => $date, 'kind' => Row::text($original, 'kind'), 'obligation_limit_minor' => Row::text($original, 'prior_limit_minor'),
                'prior_limit_minor' => Row::text($original, 'obligation_limit_minor'), 'unimpaired_minor' => Row::nullable($original, 'unimpaired_minor'), 'counter_account_id' => Row::nullable($original, 'counter_account_id'),
                'journal_id' => $journal?->id, 'reverses_id' => $eventId, 'evidence' => '{}', 'reason' => $reason,
                'fraction' => Row::nullable($original, 'fraction'), 'gain_account_id' => Row::nullable($original, 'gain_account_id'), 'loss_account_id' => Row::nullable($original, 'loss_account_id'),
                'oci_transfer_account_id' => Row::nullable($original, 'oci_transfer_account_id'), 'retained_account_id' => Row::nullable($original, 'retained_account_id'),
                'retained_fair_value_minor' => Row::nullable($original, 'retained_fair_value_minor'), 'net_assets_minor' => Row::nullable($original, 'net_assets_minor'), 'oci_release_minor' => (string) BigInteger::of(Row::text($original, 'oci_release_minor'))->negated()]);

            return $id;
        }, $bookId);
    }

    /** @param array<string, mixed> $evidence
     * @param  array<string, ?string>  $terms
     */
    private function event(string $bookId, string $associateId, string $key, string $date, string $kind, Money $amount, string $reason, array $evidence,
        ?Money $obligationLimit = null, ?Money $unimpairedCarrying = null, ?string $counterAccountId = null, array $terms = []): string
    {
        Assert::text($key, 128);
        Assert::date($date);
        Assert::text($reason, 1024);

        return $this->db->run(Permission::Investments, function () use ($bookId, $associateId, $key, $date, $kind, $amount, $reason, $evidence, $obligationLimit, $unimpairedCarrying, $counterAccountId, $terms): string {
            $book = $this->db->lockBook($bookId);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            Assert::that($amount->currency == $currency && ($obligationLimit === null || $obligationLimit->currency == $currency)
                && ($unimpairedCarrying === null || $unimpairedCarrying->currency == $currency), 'Associate events require investor functional currency.');
            $fingerprint = Canonical::hash($terms === [] ? [$associateId, $date, $kind, $amount, $reason, $evidence, $obligationLimit, $unimpairedCarrying, $counterAccountId]
                : [$associateId, $date, $kind, $amount, $reason, $evidence, $obligationLimit, $unimpairedCarrying, $counterAccountId, $terms]);
            $replay = $this->replay($bookId, $key, $fingerprint);
            if ($replay !== null) {
                return $replay;
            }
            $definition = $this->db->find('ledger_associates', $associateId, $bookId);
            $dimensions = [];
            foreach (Row::json($definition, 'dimensions') as $name => $value) {
                Assert::that(is_string($name) && is_string($value), 'Invalid stored associate dimensions.');
                if (is_string($name) && is_string($value)) {
                    $dimensions[$name] = $value;
                }
            }
            $row = $this->db->connection->selectOne('SELECT ledger_associate_quote(?::uuid, ?::uuid, ?::uuid, ?::date, ?, ?::numeric, ?::numeric, ?::numeric, ?::uuid, ?::jsonb)::text AS result',
                [$this->db->scope->tenantId, $bookId, $associateId, $date, $kind, (string) $amount->minor, $obligationLimit === null ? null : (string) $obligationLimit->minor,
                    $unimpairedCarrying === null ? null : (string) $unimpairedCarrying->minor, $counterAccountId, $terms === [] ? null : json_encode($terms, JSON_THROW_ON_ERROR)]);
            Assert::that($row instanceof stdClass, 'Associate event quote is missing.');
            $quote = (object) ($row instanceof stdClass ? Row::json($row, 'result') : []);
            $id = (string) Str::uuid7();
            $effects = [];
            $postings = $quote->postings ?? [];
            Assert::that(is_array($postings), 'Associate posting quote is invalid.');
            foreach (is_array($postings) ? $postings : [] as $posting) {
                $line = (object) $posting;
                $value = Money::minor(Row::text($line, 'amount_minor'), $currency);
                $effects[] = [Row::text($line, 'account_id'), Row::text($line, 'side') === 'debit' ? $value : $value->negated()];
            }
            $cashAccount = match ($kind) {
                'dividend' => Row::text($definition, 'dividend_account_id'), 'settlement', 'disposal', 'increase' => $counterAccountId,
                'cancellation' => Row::text($definition, 'funding_account_id'), default => null,
            };
            $cashAmount = match ($kind) {
                'dividend' => Money::minor(Row::text($quote, 'share_minor'), $currency), 'settlement', 'increase' => $amount->negated(), 'disposal' => $amount,
                'cancellation' => Money::minor(Row::text($definition, 'cost_minor'), $currency), default => null,
            };
            $journal = $kind === 'cancellation' && Row::nullable($definition, 'journal_id') !== null
                ? $this->journals->reverse($bookId, Row::text($definition, 'journal_id'), 'associate-cancel:'.hash('sha256', $key), $date, $reason)
                : $this->post($bookId, 'associate-event:'.hash('sha256', $key), $date, $reason, $effects, $id, $cashAccount, $cashAmount, $kind === 'dividend' ? Row::text($definition, 'dividend_cash_flow') : 'investing', $dimensions);
            $values = [];
            foreach (['share_minor', 'carrying_delta', 'liability_delta', 'unrecognized_profit_delta', 'unrecognized_oci_delta', 'impairment_delta', 'obligation_limit_minor', 'prior_limit_minor'] as $name) {
                $values[$name] = Row::text($quote, $name);
            }
            $this->db->insert('ledger_associate_events', $values + ['id' => $id, 'book_id' => $bookId, 'associate_id' => $associateId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
                'effective_on' => $date, 'kind' => $kind, 'source_minor' => (string) $amount->minor, 'unimpaired_minor' => $unimpairedCarrying === null ? null : (string) $unimpairedCarrying->minor,
                'counter_account_id' => $counterAccountId, 'journal_id' => $journal?->id, 'evidence' => $evidence === [] ? '{}' : Canonical::json($evidence), 'reason' => $reason,
                'oci_release_minor' => Row::text($quote, 'oci_release_minor')] + array_filter($terms, fn (?string $value): bool => $value !== null));

            return $id;
        }, $bookId);
    }

    /** @param list<array{string|null, Money}> $effects
     * @param  array<string, string>  $dimensions
     */
    private function post(string $bookId, string $key, string $date, string $reason, array $effects, string $sourceId, ?string $cashAccountId = null, ?Money $cashAmount = null, string $cashCategory = 'investing', array $dimensions = []): ?Journal
    {
        $postings = [];
        foreach ($effects as [$account, $amount]) {
            if (! $amount->minor->isZero()) {
                Assert::that($account !== null, 'Associate posting account is missing.');
                $postings[] = new Posting($account ?? '', $amount->minor->isPositive() ? Side::Debit : Side::Credit, Money::minor((string) $amount->minor->abs(), $amount->currency), dimensions: $dimensions);
            }
        }
        if ($postings === []) {
            return null;
        }
        $metadata = ['associate_source_id' => $sourceId];
        if ($cashAccountId !== null && $cashAmount !== null && Row::nullable($this->db->find('ledger_accounts', $cashAccountId, $bookId), 'cash_flow') === 'cash') {
            $metadata['cash_flow'] = [$cashCategory => (string) $cashAmount->minor];
        }

        return $this->journals->post(new JournalRequest($bookId, $key, $date, $reason, $postings, $metadata, source: new Source('associate', $sourceId)));
    }

    private function replay(string $bookId, string $key, string $fingerprint): ?string
    {
        $row = $this->db->table('ledger_associate_events')->where('book_id', $bookId)->where('idempotency_key', $key)->first();
        if (! $row instanceof stdClass) {
            return null;
        }
        Assert::that(Row::text($row, 'fingerprint') === $fingerprint, 'Associate event key already contains different content.');

        return Row::text($row, 'id');
    }
}
