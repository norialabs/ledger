<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger\Tax;

use NoriaLabs\Ledger\Money\Money;

final readonly class TaxResult
{
    /** @param array<string, Money> $taxes */
    public function __construct(public Money $net, public array $taxes, public Money $gross, public Money $roundingAdjustment) {}
}
