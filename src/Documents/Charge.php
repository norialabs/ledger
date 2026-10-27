<?php

declare(strict_types=1);

namespace Noria\Ledger\Documents;

use JsonSerializable;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Support\Assert;

final readonly class Charge implements JsonSerializable
{
    /** @param array<string, string> $dimensions */
    public function __construct(public string $accountId, public Money $amount, public string $description, public array $dimensions = [])
    {
        Assert::uuid($accountId);
        Assert::text($description, 1024);
        Assert::that($amount->minor->isPositive(), 'Charge amount must be positive.');
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['account_id' => $this->accountId, 'amount' => $this->amount, 'description' => $this->description, 'dimensions' => $this->dimensions];
    }
}
