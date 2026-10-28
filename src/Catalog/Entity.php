<?php

declare(strict_types=1);

namespace Noria\Ledger\Catalog;

use Noria\Ledger\Support\Row;
use stdClass;

final readonly class Entity
{
    public function __construct(public string $id, public string $name, public string $country, public bool $deleted) {}

    public static function fromRow(stdClass $row): self
    {
        return new self(Row::text($row, 'id'), Row::text($row, 'name'), Row::text($row, 'country'), Row::nullable($row, 'deleted_at') !== null);
    }
}
