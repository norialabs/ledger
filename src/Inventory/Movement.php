<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger\Inventory;

use NoriaLabs\Ledger\Money\Money;

final readonly class Movement
{
    public function __construct(public string $id, public ?string $journalId, public Quantity $quantity, public Money $value) {}
}
