<?php

declare(strict_types=1);

namespace Noria\Ledger\Imports;

enum ImportKind: string
{
    case BankStatement = 'bank_statement';
    case Parties = 'parties';
    case Accounts = 'accounts';
    case Journals = 'journals';
    case ExchangeRates = 'exchange_rates';
}
