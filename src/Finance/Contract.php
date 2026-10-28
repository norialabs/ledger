<?php

declare(strict_types=1);

namespace Noria\Ledger\Finance;

use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Support\Row;
use stdClass;

/** @phpstan-type Plan array{plan_id: string, plan_sequence: string, effective_on: string, carrying_on: string, payments: list<array{due_on: string, payment: string}>, rate_per_slot: string, rate_period_months: ?int, next_line: int, requires_actual_payment: bool, scheduled_carrying_minor: string, measurement: string} */
final readonly class Contract
{
    /** @param array<array-key, mixed> $inputs
     * @param  array<string, string>  $dimensions
     */
    public function __construct(
        public string $id,
        public string $bookId,
        public string $idempotencyKey,
        public string $fingerprint,
        public string $sequence,
        public string $recordedAt,
        public string $actorId,
        public ?string $firmId,
        public string $reference,
        public string $kind,
        public string $effectiveOn,
        public string $configurationId,
        public array $inputs,
        public array $dimensions,
        public string $carryingAccountId,
        public ?string $allowanceAccountId,
        public ?string $assetAccountId,
        public Money $opening,
        public string $journalId,
    ) {}

    public static function fromRow(stdClass $row, Currency $currency): self
    {
        return new self(Row::text($row, 'id'), Row::text($row, 'book_id'), Row::text($row, 'idempotency_key'), Row::text($row, 'fingerprint'),
            Row::text($row, 'sequence'), Row::text($row, 'recorded_at'), Row::text($row, 'actor_id'), Row::nullable($row, 'firm_id'),
            Row::text($row, 'reference'), Row::text($row, 'kind'), Row::text($row, 'effective_on'), Row::text($row, 'configuration_id'),
            Row::json($row, 'inputs'), Row::dimensions($row), Row::text($row, 'carrying_account_id'), Row::nullable($row, 'allowance_account_id'),
            Row::nullable($row, 'asset_account_id'), Money::minor(Row::text($row, 'opening_minor'), $currency), Row::text($row, 'journal_id'));
    }

    /** @return Plan */
    public static function plan(stdClass $row): array
    {
        $months = Row::nullable($row, 'rate_period_months');

        return ['plan_id' => Row::text($row, 'plan_id'), 'plan_sequence' => Row::text($row, 'plan_sequence'),
            'effective_on' => Row::text($row, 'effective_on'), 'carrying_on' => Row::text($row, 'carrying_on'),
            'payments' => Row::objects($row->payments, fn (stdClass $r): array => ['due_on' => Row::text($r, 'due_on'), 'payment' => Row::text($r, 'payment')]),
            'rate_per_slot' => Row::text($row, 'rate_per_slot'), 'rate_period_months' => $months === null ? null : (int) $months,
            'next_line' => (int) Row::text($row, 'next_line'), 'requires_actual_payment' => Row::boolean($row, 'requires_actual_payment'),
            'scheduled_carrying_minor' => Row::text($row, 'scheduled_carrying_minor'), 'measurement' => Row::text($row, 'measurement')];
    }
}
