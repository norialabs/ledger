<?php

declare(strict_types=1);

namespace Noria\Ledger\Assets;

use Noria\Ledger\Money\Money;

final readonly class AssetPosition
{
    public function __construct(public Money $cost, public Money $depreciation, public Money $impairment, public Money $carrying,
        public Money $residual, public Money $depreciationDue, public bool $disposed, public string $planId,
        public Money $availableSurplus, public Money $unrecoveredLoss) {}
}
