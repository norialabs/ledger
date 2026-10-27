<?php

declare(strict_types=1);

namespace Noria\Ledger\Inventory;

use Noria\Ledger\Money\Money;

final readonly class Movement
{
    public function __construct(public string $id, public ?string $journalId, public Quantity $quantity, public Money $value, public ?Money $commercialValue = null) {}
}
