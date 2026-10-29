<?php

declare(strict_types=1);

namespace Noria\Ledger\Reporting;

enum StatementKind: string
{
    case ProfitOrLoss = 'profit_or_loss';
    case ComprehensiveIncome = 'comprehensive_income';
    case ChangesInEquity = 'changes_in_equity';

    public function suffix(): string
    {
        return match ($this) {
            self::ProfitOrLoss => '',
            self::ComprehensiveIncome => ' (comprehensive income)',
            self::ChangesInEquity => ' (changes in equity)',
        };
    }
}
