<?php

declare(strict_types=1);

namespace Noria\Ledger\Reporting;

use JsonSerializable;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Support\Assert;

final readonly class BudgetLine implements JsonSerializable
{
    /** @param array<string, string> $dimensions */
    public function __construct(public string $accountId, public string $startsOn, public string $endsOn, public Money $amount, public array $dimensions = [])
    {
        Assert::uuid($accountId);
        Assert::date($startsOn);
        Assert::date($endsOn);
        Assert::that($startsOn <= $endsOn, 'Budget line ends before it starts.');
        foreach ($dimensions as $key => $value) {
            Assert::text($key, 64);
            Assert::text($value, 128);
        }
    }

    /** @return array{account_id: string, starts_on: string, ends_on: string, amount_minor: string, dimensions: array<string, string>} */
    public function jsonSerialize(): array
    {
        $dimensions = $this->dimensions;
        ksort($dimensions);

        return ['account_id' => $this->accountId, 'starts_on' => $this->startsOn, 'ends_on' => $this->endsOn, 'amount_minor' => (string) $this->amount->minor, 'dimensions' => $dimensions];
    }
}
