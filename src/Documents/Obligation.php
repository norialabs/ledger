<?php

declare(strict_types=1);

namespace Noria\Ledger\Documents;

use JsonSerializable;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Support\Assert;

final readonly class Obligation implements JsonSerializable
{
    /** @param array<string, string> $dimensions */
    public function __construct(public string $partyId, public Money $amount, public string $dueOn, public array $dimensions = [])
    {
        Assert::uuid($partyId);
        Assert::date($dueOn);
        Assert::that($amount->minor->isPositive(), 'Obligation amount must be positive.');
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['party_id' => $this->partyId, 'amount' => $this->amount, 'due_on' => $this->dueOn, 'dimensions' => $this->dimensions];
    }
}
