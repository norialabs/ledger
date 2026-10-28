<?php

declare(strict_types=1);

namespace Noria\Ledger\Reporting;

use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Money\Money;

final readonly class OpenItem
{
    /** @param array<string, string> $dimensions */
    public function __construct(public string $id, public string $journalId, public int $lineNo, public string $accountId,
        public string $partyId, public Side $side, public string $effectiveOn, public string $dueOn,
        public Money $original, public Money $outstanding, public Money $functionalOutstanding,
        public string $sequence, public array $dimensions) {}

    public function cursor(): string
    {
        return $this->effectiveOn.'.'.$this->sequence.'.'.$this->lineNo;
    }
}
