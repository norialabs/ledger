<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger\Accounting;

enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';

    public function normalSide(): Side
    {
        return match ($this) {
            self::Asset, self::Expense => Side::Debit,
            default => Side::Credit,
        };
    }
}
