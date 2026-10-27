<?php

declare(strict_types=1);

namespace Noria\Ledger\Inventory;

final readonly class Production
{
    /** @param list<Movement> $inputs */
    public function __construct(public string $id, public array $inputs, public Movement $output) {}
}
