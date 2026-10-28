<?php

declare(strict_types=1);

namespace Noria\Ledger\Accounting;

use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Support\Row;
use stdClass;

final readonly class JournalLine
{
    /** @param array<string, string> $dimensions */
    public function __construct(
        public string $journalId,
        public int $lineNo,
        public string $accountId,
        public Side $side,
        public Money $amount,
        public Money $functional,
        public string $rate,
        public string $rateAsOf,
        public string $rateSource,
        public ?string $partyId,
        public ?string $dueOn,
        public array $dimensions,
        public string $description,
        public bool $valuation,
    ) {}

    public static function fromRow(stdClass $row, Currency $currency, Currency $functional): self
    {
        $dimensions = [];
        foreach (Row::json($row, 'dimensions') as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $dimensions[$key] = $value;
            }
        }

        return new self(Row::text($row, 'journal_id'), (int) Row::text($row, 'line_no'), Row::text($row, 'account_id'), Side::from(Row::text($row, 'side')),
            Money::minor(Row::text($row, 'amount_minor'), $currency), Money::minor(Row::text($row, 'functional_minor'), $functional),
            Row::text($row, 'rate'), Row::text($row, 'rate_as_of'), Row::text($row, 'rate_source'), Row::nullable($row, 'party_id'),
            Row::nullable($row, 'due_on'), $dimensions, Row::text($row, 'description'), Row::boolean($row, 'valuation'));
    }
}
