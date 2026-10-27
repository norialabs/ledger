<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger\Reporting;

use NoriaLabs\Ledger\Accounting\AccountType;
use NoriaLabs\Ledger\Money\Money;

final readonly class AccountBalance
{
    public function __construct(public string $accountId, public string $code, public string $name, public AccountType $type,
        public Money $opening, public Money $debits, public Money $credits, public Money $closing) {}
}
