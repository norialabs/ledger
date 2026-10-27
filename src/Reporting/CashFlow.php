<?php

declare(strict_types=1);

namespace Noria\Ledger\Reporting;

use Noria\Ledger\Money\Money;

final readonly class CashFlow
{
    /** @param list<string> $unclassifiedJournalIds */
    public function __construct(public Money $opening, public Money $closing, public Money $operating, public Money $investing,
        public Money $financing, public Money $exchange, public Money $unclassified, public array $unclassifiedJournalIds) {}

    public function isFullyClassified(): bool
    {
        return $this->unclassifiedJournalIds === [];
    }
}
