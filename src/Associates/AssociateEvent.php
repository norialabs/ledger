<?php

declare(strict_types=1);

namespace Noria\Ledger\Associates;

use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Support\Row;
use stdClass;

final readonly class AssociateEvent
{
    /** @param array<array-key, mixed> $evidence */
    public function __construct(
        public string $id,
        public string $bookId,
        public string $idempotencyKey,
        public string $fingerprint,
        public string $sequence,
        public string $recordedAt,
        public string $actorId,
        public ?string $firmId,
        public string $associateId,
        public string $effectiveOn,
        public string $kind,
        public Money $source,
        public Money $share,
        public Money $carryingDelta,
        public Money $liabilityDelta,
        public Money $unrecognizedProfitDelta,
        public Money $unrecognizedOciDelta,
        public Money $impairmentDelta,
        public Money $obligationLimit,
        public Money $priorLimit,
        public ?Money $unimpaired,
        public ?string $counterAccountId,
        public ?string $fraction,
        public ?string $gainAccountId,
        public ?string $lossAccountId,
        public ?string $ociTransferAccountId,
        public ?string $retainedAccountId,
        public ?Money $retainedFairValue,
        public Money $ociRelease,
        public ?Money $netAssets,
        public ?string $journalId,
        public ?string $reversesId,
        public array $evidence,
        public string $reason,
    ) {}

    public static function fromRow(stdClass $row, Currency $currency): self
    {
        $money = fn (string $key): Money => Money::minor(Row::text($row, $key), $currency);
        $optional = fn (string $key): ?Money => Row::nullable($row, $key) === null ? null : $money($key);

        return new self(Row::text($row, 'id'), Row::text($row, 'book_id'), Row::text($row, 'idempotency_key'), Row::text($row, 'fingerprint'),
            Row::text($row, 'sequence'), Row::text($row, 'recorded_at'), Row::text($row, 'actor_id'), Row::nullable($row, 'firm_id'),
            Row::text($row, 'associate_id'), Row::text($row, 'effective_on'), Row::text($row, 'kind'), $money('source_minor'), $money('share_minor'),
            $money('carrying_delta'), $money('liability_delta'), $money('unrecognized_profit_delta'), $money('unrecognized_oci_delta'),
            $money('impairment_delta'), $money('obligation_limit_minor'), $money('prior_limit_minor'), $optional('unimpaired_minor'),
            Row::nullable($row, 'counter_account_id'), Row::nullable($row, 'fraction'), Row::nullable($row, 'gain_account_id'),
            Row::nullable($row, 'loss_account_id'), Row::nullable($row, 'oci_transfer_account_id'), Row::nullable($row, 'retained_account_id'),
            $optional('retained_fair_value_minor'), $money('oci_release_minor'), $optional('net_assets_minor'), Row::nullable($row, 'journal_id'),
            Row::nullable($row, 'reverses_id'), Row::json($row, 'evidence'), Row::text($row, 'reason'));
    }
}
