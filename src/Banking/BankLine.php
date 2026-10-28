<?php

declare(strict_types=1);

namespace Noria\Ledger\Banking;

use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Support\Row;
use stdClass;

final readonly class BankLine
{
    public function __construct(
        public string $id,
        public string $bookId,
        public string $statementId,
        public string $accountId,
        public int $lineNo,
        public string $externalId,
        public string $valueOn,
        public Money $amount,
        public string $description,
    ) {}

    public static function fromRow(stdClass $row, Currency $currency): self
    {
        return new self(Row::text($row, 'id'), Row::text($row, 'book_id'), Row::text($row, 'statement_id'), Row::text($row, 'account_id'),
            (int) Row::text($row, 'line_no'), Row::text($row, 'external_id'), Row::text($row, 'value_on'),
            Money::minor(Row::text($row, 'amount_minor'), $currency), Row::text($row, 'description'));
    }
}
