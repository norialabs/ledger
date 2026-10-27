<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger\Accounting;

enum Side: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    public function opposite(): self
    {
        return $this === self::Debit ? self::Credit : self::Debit;
    }

    public function sign(): int
    {
        return $this === self::Debit ? 1 : -1;
    }
}
