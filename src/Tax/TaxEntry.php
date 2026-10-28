<?php

declare(strict_types=1);

namespace Noria\Ledger\Tax;

use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Support\Row;
use stdClass;

final readonly class TaxEntry
{
    /** @param list<string> $partyIds */
    public function __construct(
        public string $journalId,
        public string $originId,
        public string $effectiveOn,
        public string $sequence,
        public int $lineNo,
        public string $taxCode,
        public string $kind,
        public string $configurationId,
        public ?string $accountId,
        public string $sourceType,
        public string $sourceReference,
        public array $partyIds,
        public Money $base,
        public Money $tax,
        public Money $ledger,
        public bool $claimable,
        public bool $requiresPayment,
        public bool $isReversal,
    ) {}

    public static function fromRow(stdClass $row, Currency $currency): self
    {
        $parties = is_string($row->party_ids ?? null) ? Row::json($row, 'party_ids') : $row->party_ids ?? null;

        return new self(Row::text($row, 'journal_id'), Row::text($row, 'origin_id'), Row::text($row, 'effective_on'), Row::text($row, 'sequence'),
            (int) Row::text($row, 'line_no'), Row::text($row, 'tax_code'), Row::text($row, 'kind'), Row::text($row, 'configuration_id'),
            Row::nullable($row, 'account_id'), Row::text($row, 'source_type'), Row::text($row, 'source_reference'), Row::strings($parties),
            Money::minor(Row::text($row, 'base_minor'), $currency), Money::minor(Row::text($row, 'tax_minor'), $currency),
            Money::minor(Row::text($row, 'ledger_minor'), $currency), Row::boolean($row, 'claimable'), Row::boolean($row, 'requires_payment'),
            Row::boolean($row, 'is_reversal'));
    }
}
