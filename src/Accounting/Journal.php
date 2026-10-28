<?php

declare(strict_types=1);

namespace Noria\Ledger\Accounting;

use Noria\Ledger\Support\Row;
use stdClass;

final readonly class Journal
{
    /** @param array<array-key, mixed> $metadata */
    public function __construct(
        public string $id,
        public string $bookId,
        public string $effectiveOn,
        public string $recordedAt,
        public string $sequence,
        public string $description = '',
        public ?string $reversesId = null,
        public bool $closing = false,
        public ?Source $source = null,
        public string $actorId = '',
        public ?string $firmId = null,
        public array $metadata = [],
    ) {}

    public static function fromRow(stdClass $row): self
    {
        $sourceType = Row::nullable($row, 'source_type');

        return new self(Row::text($row, 'id'), Row::text($row, 'book_id'), Row::text($row, 'effective_on'), Row::text($row, 'recorded_at'), Row::text($row, 'sequence'),
            Row::text($row, 'description'), Row::nullable($row, 'reverses_id'), Row::boolean($row, 'closing'),
            $sourceType === null ? null : new Source($sourceType, Row::text($row, 'source_reference')),
            Row::text($row, 'actor_id'), Row::nullable($row, 'firm_id'), Row::json($row, 'metadata'));
    }
}
