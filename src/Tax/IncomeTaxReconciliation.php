<?php

declare(strict_types=1);

namespace Noria\Ledger\Tax;

use Noria\Ledger\Money\Money;

final readonly class IncomeTaxReconciliation
{
    public Money $expectedPayable;

    public Money $bookedPayable;

    public Money $difference;

    public function __construct(public string $computationId, public string $status, public Money $charge, public Money $credits,
        public Money $bookedCharge, public Money $bookedCredits)
    {
        $this->expectedPayable = $charge->minus($credits);
        $this->bookedPayable = $bookedCharge->minus($bookedCredits);
        $this->difference = $this->bookedPayable->minus($this->expectedPayable);
    }
}
