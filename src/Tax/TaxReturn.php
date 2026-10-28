<?php

declare(strict_types=1);

namespace Noria\Ledger\Tax;

use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Support\Row;
use stdClass;

/**
 * @phpstan-type Source array{journal_id: string, line_no: int, tax_code: string}
 * @phpstan-type Result array{entries: list<TaxEntry>, claims: list<array{decision_id: ?string, journal_id: string, line_no: int, tax_code: string, effective_on: string, amount_minor: string}>, bases: array<string, string>, totals: array{output_tax: string, input_claimed: string, withholding_claimed: string, reverse_charge_tax: string, withholding_payable: string}, reconciliation: list<array{account_id: string, role: string, ledger_minor: string, explained_minor: string, difference_minor: string}>, reconciliation_decisions: list<array{decision_id: string, journal_id: string, line_no: int, classification: string, amount_minor: string}>, unreconciled: list<array{journal_id: string, line_no: int, account_id: string}>, unreviewed: list<Source>, unmapped: list<Source|array{decision_id: ?string, tax_code: string}|array{decision_id: string, classification: string}>, invalid_claims: list<string>, opening_credit_minor: string, adjustments_minor: string, net_minor: string, credit_carried_minor: string, configuration_hash: string, currency: string}
 */
final readonly class TaxReturn
{
    public function __construct(
        public string $id,
        public string $bookId,
        public string $idempotencyKey,
        public string $fingerprint,
        public string $sequence,
        public string $recordedAt,
        public string $actorId,
        public ?string $firmId,
        public string $configurationId,
        public string $scheme,
        public string $startsOn,
        public string $effectiveOn,
        public string $throughSequence,
        public ?string $previousId,
        public ?string $priorPeriodId,
        public Money $openingCredit,
        public ?string $openingReference,
    ) {}

    public static function fromRow(stdClass $row, Currency $currency): self
    {
        return new self(Row::text($row, 'id'), Row::text($row, 'book_id'), Row::text($row, 'idempotency_key'), Row::text($row, 'fingerprint'),
            Row::text($row, 'sequence'), Row::text($row, 'recorded_at'), Row::text($row, 'actor_id'), Row::nullable($row, 'firm_id'),
            Row::text($row, 'configuration_id'), Row::text($row, 'scheme'), Row::text($row, 'starts_on'), Row::text($row, 'effective_on'),
            Row::text($row, 'through_sequence'), Row::nullable($row, 'previous_id'), Row::nullable($row, 'prior_period_id'),
            Money::minor(Row::text($row, 'opening_credit_minor'), $currency), Row::nullable($row, 'opening_reference'));
    }

    /** @return Result */
    public static function result(string|stdClass $json, Currency $currency): array
    {
        $row = $json instanceof stdClass ? $json : Row::object(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        $totals = Row::object($row->totals);

        return [
            'entries' => Row::objects($row->entries, fn (stdClass $r): TaxEntry => TaxEntry::fromRow($r, $currency)),
            'claims' => Row::objects($row->claims, fn (stdClass $r): array => ['decision_id' => Row::nullable($r, 'decision_id'), 'journal_id' => Row::text($r, 'journal_id'), 'line_no' => (int) Row::text($r, 'line_no'), 'tax_code' => Row::text($r, 'tax_code'), 'effective_on' => Row::text($r, 'effective_on'), 'amount_minor' => Row::text($r, 'amount_minor')]),
            'bases' => Row::stringMap($row->bases),
            'totals' => ['output_tax' => Row::text($totals, 'output_tax'), 'input_claimed' => Row::text($totals, 'input_claimed'), 'withholding_claimed' => Row::text($totals, 'withholding_claimed'), 'reverse_charge_tax' => Row::text($totals, 'reverse_charge_tax'), 'withholding_payable' => Row::text($totals, 'withholding_payable')],
            'reconciliation' => Row::objects($row->reconciliation, fn (stdClass $r): array => ['account_id' => Row::text($r, 'account_id'), 'role' => Row::text($r, 'role'), 'ledger_minor' => Row::text($r, 'ledger_minor'), 'explained_minor' => Row::text($r, 'explained_minor'), 'difference_minor' => Row::text($r, 'difference_minor')]),
            'reconciliation_decisions' => Row::objects($row->reconciliation_decisions, fn (stdClass $r): array => ['decision_id' => Row::text($r, 'decision_id'), 'journal_id' => Row::text($r, 'journal_id'), 'line_no' => (int) Row::text($r, 'line_no'), 'classification' => Row::text($r, 'classification'), 'amount_minor' => Row::text($r, 'amount_minor')]),
            'unreconciled' => Row::objects($row->unreconciled, fn (stdClass $r): array => ['journal_id' => Row::text($r, 'journal_id'), 'line_no' => (int) Row::text($r, 'line_no'), 'account_id' => Row::text($r, 'account_id')]),
            'unreviewed' => Row::objects($row->unreviewed, self::source(...)),
            'unmapped' => Row::objects($row->unmapped, self::unmapped(...)),
            'invalid_claims' => Row::strings($row->invalid_claims),
            'opening_credit_minor' => Row::text($row, 'opening_credit_minor'), 'adjustments_minor' => Row::text($row, 'adjustments_minor'),
            'net_minor' => Row::text($row, 'net_minor'), 'credit_carried_minor' => Row::text($row, 'credit_carried_minor'),
            'configuration_hash' => Row::text($row, 'configuration_hash'), 'currency' => Row::text($row, 'currency'),
        ];
    }

    /** @return Source */
    private static function source(stdClass $r): array
    {
        return ['journal_id' => Row::text($r, 'journal_id'), 'line_no' => (int) Row::text($r, 'line_no'), 'tax_code' => Row::text($r, 'tax_code')];
    }

    /** @return Source|array{decision_id: ?string, tax_code: string}|array{decision_id: string, classification: string} */
    private static function unmapped(stdClass $r): array
    {
        if (isset($r->journal_id)) {
            return self::source($r);
        }
        if (isset($r->classification)) {
            return ['decision_id' => Row::text($r, 'decision_id'), 'classification' => Row::text($r, 'classification')];
        }

        return ['decision_id' => Row::nullable($r, 'decision_id'), 'tax_code' => Row::text($r, 'tax_code')];
    }
}
