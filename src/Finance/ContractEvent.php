<?php

declare(strict_types=1);

namespace Noria\Ledger\Finance;

use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Support\Row;
use stdClass;

final readonly class ContractEvent
{
    /** @param array<array-key, mixed> $inputs
     * @param  array<array-key, mixed>|null  $calculation
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
        public string $contractId,
        public string $effectiveOn,
        public string $kind,
        public array $inputs,
        public Money $carryingDelta,
        public Money $allowanceDelta,
        public Money $writtenOffDelta,
        public Money $recoveredDelta,
        public int $stage,
        public int $priorStage,
        public ?string $journalId,
        public ?string $reversesId,
        public string $reason,
        public ?array $calculation = null,
    ) {}

    public static function fromRow(stdClass $row, Currency $currency): self
    {
        return new self(Row::text($row, 'id'), Row::text($row, 'book_id'), Row::text($row, 'idempotency_key'), Row::text($row, 'fingerprint'),
            Row::text($row, 'sequence'), Row::text($row, 'recorded_at'), Row::text($row, 'actor_id'), Row::nullable($row, 'firm_id'),
            Row::text($row, 'contract_id'), Row::text($row, 'effective_on'), Row::text($row, 'kind'), Row::json($row, 'inputs'),
            Money::minor(Row::text($row, 'carrying_delta'), $currency), Money::minor(Row::text($row, 'allowance_delta'), $currency),
            Money::minor(Row::text($row, 'written_off_delta'), $currency), Money::minor(Row::text($row, 'recovered_delta'), $currency),
            (int) Row::text($row, 'stage'), (int) Row::text($row, 'prior_stage'), Row::nullable($row, 'journal_id'), Row::nullable($row, 'reverses_id'),
            Row::text($row, 'reason'), Row::nullable($row, 'calculation') === null ? null : Row::json($row, 'calculation'));
    }
}
