<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger\Inventory;

use NoriaLabs\Ledger\Money\Money;

final readonly class StockBalance
{
    public function __construct(public Quantity $quantity, public Money $value) {}
}
