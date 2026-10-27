<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger\Monetary;

use NoriaLabs\Ledger\Money\Money;

final readonly class Valuation
{
    public function __construct(public string $id, public ?string $journalId, public Money $sourceBalance, public Money $carryingBefore, public Money $adjustment) {}

    public function carryingAfter(): Money
    {
        return $this->carryingBefore->plus($this->adjustment);
    }
}
