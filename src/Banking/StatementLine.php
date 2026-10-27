<?php

declare(strict_types=1);

namespace Noria\Ledger\Banking;

use JsonSerializable;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Support\Assert;

final readonly class StatementLine implements JsonSerializable
{
    public function __construct(public string $externalId, public string $valueOn, public Money $amount, public string $description)
    {
        Assert::text($externalId, 128);
        Assert::date($valueOn);
        Assert::text($description, 1024);
        Assert::that(! $amount->minor->isZero(), 'Bank statement movements cannot be zero.');
    }

    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return ['external_id' => $this->externalId, 'value_on' => $this->valueOn, 'amount_minor' => (string) $this->amount->minor, 'description' => $this->description];
    }
}
