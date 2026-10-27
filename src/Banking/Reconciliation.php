<?php

declare(strict_types=1);

namespace Noria\Ledger\Banking;

use Noria\Ledger\Money\Money;

final readonly class Reconciliation
{
    public function __construct(public Money $ledgerBalance, public Money $statementBalance,
        public Money $unmatchedLedger, public Money $unmatchedStatement, public Money $unexplained) {}
}
