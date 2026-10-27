<?php

declare(strict_types=1);

namespace Noria\Ledger\Inventory;

use Noria\Ledger\Money\Money;

final readonly class StockBalance
{
    public function __construct(public Quantity $quantity, public Money $value) {}
}
