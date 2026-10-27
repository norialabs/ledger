<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger\Accounting;

use NoriaLabs\Ledger\Support\Row;
use stdClass;

final readonly class Journal
{
    public function __construct(public string $id, public string $bookId, public string $effectiveOn, public string $recordedAt, public string $sequence) {}

    public static function fromRow(stdClass $row): self
    {
        return new self(Row::text($row, 'id'), Row::text($row, 'book_id'), Row::text($row, 'effective_on'), Row::text($row, 'recorded_at'), Row::text($row, 'sequence'));
    }
}
