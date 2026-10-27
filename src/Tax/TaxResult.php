<?php

declare(strict_types=1);

namespace Noria\Ledger\Tax;

use JsonSerializable;
use Noria\Ledger\Money\Money;

final readonly class TaxResult implements JsonSerializable
{
    /** @param array<string, Money> $taxes */
    public function __construct(public Money $net, public array $taxes, public Money $gross, public Money $roundingAdjustment) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['net' => $this->net, 'taxes' => $this->taxes, 'gross' => $this->gross, 'rounding_adjustment' => $this->roundingAdjustment];
    }
}
