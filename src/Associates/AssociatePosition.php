<?php

declare(strict_types=1);

namespace Noria\Ledger\Associates;

use Noria\Ledger\Money\Money;

final readonly class AssociatePosition
{
    public function __construct(public Money $carrying, public Money $lossLiability, public Money $unrecognizedProfitLoss,
        public Money $unrecognizedOci, public Money $impairment, public Money $obligationLimit, public Money $embeddedGoodwill,
        public Money $bargainPurchaseGain, public bool $cancelled, public string $ownershipFraction = '0', public bool $disposed = false, public ?Money $ociReserve = null, public bool $heldForSale = false) {}
}
