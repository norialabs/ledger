<?php

declare(strict_types=1);

namespace Noria\Ledger\Reporting;

use Noria\Ledger\Money\Money;

final readonly class BudgetPosition
{
    public Money $available;

    public function __construct(public int $lineNo, public BudgetLine $line, public Money $actual, public Money $openCommitment)
    {
        $this->available = $line->amount->minus($actual)->minus($openCommitment);
    }
}
