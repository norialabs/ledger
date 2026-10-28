<?php

declare(strict_types=1);

namespace Noria\Ledger\Catalog;

use Noria\Ledger\Support\Row;
use stdClass;

final readonly class Party
{
    public function __construct(public string $id, public string $entityId, public string $reference, public string $name, public bool $deleted) {}

    public static function fromRow(stdClass $row): self
    {
        return new self(Row::text($row, 'id'), Row::text($row, 'entity_id'), Row::text($row, 'reference'), Row::text($row, 'name'), Row::nullable($row, 'deleted_at') !== null);
    }
}
