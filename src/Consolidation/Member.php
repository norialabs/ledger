<?php

declare(strict_types=1);

namespace Noria\Ledger\Consolidation;

use Brick\Math\BigRational;
use JsonSerializable;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Reporting\Snapshot;
use Noria\Ledger\Support\Assert;

final readonly class Member implements JsonSerializable
{
    public string $ownershipFraction;

    /** @param array<string, string> $accountMap
     * @param  array<string, ExchangeRate>  $historicalEquityRates
     * @param  array<string, Money>  $historicalEquityAmounts
     */
    public function __construct(public Snapshot $snapshot, public array $accountMap, public ?ExchangeRate $closingRate = null,
        public ?ExchangeRate $incomeRate = null, public array $historicalEquityRates = [], public array $historicalEquityAmounts = [], string $ownershipFraction = '1')
    {
        foreach ($accountMap as $source => $target) {
            Assert::uuid($source);
            Assert::uuid($target);
        }

        foreach (array_keys($historicalEquityAmounts) as $account) {
            Assert::uuid($account);
            Assert::that(! isset($historicalEquityRates[$account]), 'Historical equity requires either a translated amount or a rate.');
        }

        $fraction = BigRational::of(Assert::decimal($ownershipFraction));
        Assert::that($fraction->isPositive() && $fraction->isLessThanOrEqualTo(1), 'Consolidation ownership must be greater than zero and at most one.');
        $this->ownershipFraction = $fraction->isEqualTo(1) ? '1' : $ownershipFraction;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['snapshot' => $this->snapshot, 'account_map' => $this->accountMap, 'closing_rate' => $this->closingRate,
            'income_rate' => $this->incomeRate, 'historical_equity_rates' => $this->historicalEquityRates, 'historical_equity_amounts' => $this->historicalEquityAmounts,
            'ownership_fraction' => $this->ownershipFraction];
    }
}
