<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger\Consolidation;

use JsonSerializable;
use NoriaLabs\Ledger\Money\ExchangeRate;
use NoriaLabs\Ledger\Money\Money;
use NoriaLabs\Ledger\Reporting\Snapshot;
use NoriaLabs\Ledger\Support\Assert;

final readonly class Member implements JsonSerializable
{
    /** @param array<string, string> $accountMap
     * @param  array<string, ExchangeRate>  $historicalEquityRates
     * @param  array<string, Money>  $historicalEquityAmounts
     */
    public function __construct(public Snapshot $snapshot, public array $accountMap, public ?ExchangeRate $closingRate = null,
        public ?ExchangeRate $incomeRate = null, public array $historicalEquityRates = [], public array $historicalEquityAmounts = [])
    {
        foreach ($accountMap as $source => $target) {
            Assert::uuid($source);
            Assert::uuid($target);
        }

        foreach (array_keys($historicalEquityAmounts) as $account) {
            Assert::uuid($account);
            Assert::that(! isset($historicalEquityRates[$account]), 'Historical equity requires either a translated amount or a rate.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['snapshot' => $this->snapshot, 'account_map' => $this->accountMap, 'closing_rate' => $this->closingRate,
            'income_rate' => $this->incomeRate, 'historical_equity_rates' => $this->historicalEquityRates, 'historical_equity_amounts' => $this->historicalEquityAmounts];
    }
}
