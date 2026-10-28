<?php

declare(strict_types=1);

namespace Noria\Ledger\Reporting;

use Noria\Ledger\Money\Money;

final readonly class PartyStatement
{
    /** @param list<array{journal_id: string, line_no: int, effective_on: string, sequence: string, due_on: ?string, description: string, debit: Money, credit: Money, balance: Money, functional_balance: Money}> $lines */
    public function __construct(
        public string $accountId,
        public string $partyId,
        public string $startsOn,
        public string $endsOn,
        public Money $opening,
        public Money $functionalOpening,
        public array $lines,
        public Money $closing,
        public Money $functionalClosing,
    ) {}
}
