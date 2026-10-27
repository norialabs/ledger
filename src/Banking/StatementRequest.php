<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger\Banking;

use JsonSerializable;
use NoriaLabs\Ledger\Money\Money;
use NoriaLabs\Ledger\Support\Assert;

final readonly class StatementRequest implements JsonSerializable
{
    /** @param list<StatementLine> $lines */
    public function __construct(public string $bookId, public string $accountId, public string $reference, public string $startsOn,
        public string $endsOn, public Money $opening, public Money $closing, public array $lines)
    {
        Assert::uuid($bookId);
        Assert::uuid($accountId);
        Assert::text($reference, 128);
        Assert::date($startsOn);
        Assert::date($endsOn);
        Assert::that($startsOn <= $endsOn && count($lines) <= 10000, 'Invalid statement date range or more than 10,000 movements.');
        $total = $opening;

        foreach ($lines as $line) {
            Assert::that($line->valueOn >= $startsOn && $line->valueOn <= $endsOn, 'Movement falls outside the statement.');
            $total = $total->plus($line->amount);
        }

        $total->sameCurrency($closing);
        Assert::that($total->minor->isEqualTo($closing->minor), 'Statement opening plus movements must equal its closing balance.');
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['book_id' => $this->bookId, 'account_id' => $this->accountId, 'reference' => $this->reference, 'starts_on' => $this->startsOn,
            'ends_on' => $this->endsOn, 'opening' => $this->opening, 'closing' => $this->closing, 'lines' => $this->lines];
    }
}
