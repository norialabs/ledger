<?php

declare(strict_types=1);

namespace Noria\Ledger\Catalog;

use Noria\Ledger\Support\Row;
use stdClass;

final readonly class Book
{
    public function __construct(public string $id, public string $entityId, public string $name, public string $currencyCode, public string $sequence, public bool $deleted) {}

    public static function fromRow(stdClass $row): self
    {
        return new self(Row::text($row, 'id'), Row::text($row, 'entity_id'), Row::text($row, 'name'), Row::text($row, 'currency_code'),
            Row::text($row, 'next_sequence'), Row::nullable($row, 'deleted_at') !== null);
    }
}
