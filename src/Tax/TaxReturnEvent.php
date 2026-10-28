<?php

declare(strict_types=1);

namespace Noria\Ledger\Tax;

use Noria\Ledger\Support\Row;
use stdClass;

final readonly class TaxReturnEvent
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
        public string $returnId,
        public string $effectiveOn,
        public string $kind,
        public string $reason,
        public array $evidence,
    ) {}

    public static function fromRow(stdClass $row): self
    {
        return new self(Row::text($row, 'id'), Row::text($row, 'book_id'), Row::text($row, 'idempotency_key'), Row::text($row, 'fingerprint'),
            Row::text($row, 'sequence'), Row::text($row, 'recorded_at'), Row::text($row, 'actor_id'), Row::nullable($row, 'firm_id'),
            Row::text($row, 'return_id'), Row::text($row, 'effective_on'), Row::text($row, 'kind'), Row::text($row, 'reason'), Row::json($row, 'evidence'));
    }
}
