<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger\Reporting;

use NoriaLabs\Ledger\Accounting\Side;
use NoriaLabs\Ledger\Money\Money;

final readonly class OpenItem
{
    public function __construct(public string $id, public string $journalId, public int $lineNo, public string $accountId,
        public string $partyId, public Side $side, public string $effectiveOn, public string $dueOn,
        public Money $original, public Money $outstanding, public Money $functionalOutstanding) {}
}
