<?php

declare(strict_types=1);

namespace Noria\Ledger\Reporting;

use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Money\Money;

final readonly class AccountBalance
{
    public function __construct(public string $accountId, public string $code, public string $name, public AccountType $type,
        public Money $opening, public Money $debits, public Money $credits, public Money $closing) {}
}
