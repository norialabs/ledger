<?php

declare(strict_types=1);

namespace Noria\Ledger\Catalog;

use Noria\Ledger\Support\Row;
use stdClass;

final readonly class Period
{
    public function __construct(public string $id, public string $bookId, public string $name, public string $startsOn, public string $endsOn, public bool $closed, public string $changeReason) {}

    public static function fromRow(stdClass $row): self
    {
        return new self(Row::text($row, 'id'), Row::text($row, 'book_id'), Row::text($row, 'name'), Row::text($row, 'starts_on'),
            Row::text($row, 'ends_on'), Row::boolean($row, 'closed'), Row::text($row, 'change_reason'));
    }
}
