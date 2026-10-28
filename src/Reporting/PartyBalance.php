<?php

declare(strict_types=1);

namespace Noria\Ledger\Reporting;

use Noria\Ledger\Money\Money;

final readonly class PartyBalance
{
    public function __construct(public string $partyId, public Money $balance, public Money $functionalBalance) {}
}
