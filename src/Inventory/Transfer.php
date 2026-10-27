<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger\Inventory;

final readonly class Transfer
{
    public function __construct(public string $id, public Movement $issue, public Movement $receipt) {}
}
