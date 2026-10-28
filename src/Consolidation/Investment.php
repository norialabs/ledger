<?php

declare(strict_types=1);

namespace Noria\Ledger\Consolidation;

use JsonSerializable;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Reporting\Snapshot;
use Noria\Ledger\Support\Assert;

final readonly class Investment implements JsonSerializable
{
    /** @param list<FairValue> $fairValues
     * @param  array<string, Money>  $goodwillImpairments
     */
    public function __construct(public string $investeeBookId, public string $investmentAccountId, public string $goodwillAccountId, public string $bargainAccountId,
        public Snapshot $acquisitionSnapshot, public Money $consideration, public ?ExchangeRate $acquisitionRate = null, public array $fairValues = [], public ?Money $measuredNci = null,
        public array $goodwillImpairments = [], public ?string $impairmentAccountId = null)
    {
        foreach ($goodwillImpairments as $date => $amount) {
            Assert::date((string) $date);
            Assert::that((string) $date >= $acquisitionSnapshot->effectiveOn && $amount->minor->isPositive(), 'Goodwill impairments are positive and dated on or after the acquisition.');
            $consideration->sameCurrency($amount);
        }
        Assert::that(($goodwillImpairments === []) === ($impairmentAccountId === null), 'Goodwill impairments require an impairment loss account.');
        Assert::that($goodwillImpairments === [] || $acquisitionRate === null, 'Goodwill impairment of foreign subsidiaries is not yet supported.');
        if ($impairmentAccountId !== null) {
            Assert::uuid($impairmentAccountId);
        }
        Assert::uuid($investeeBookId);
        Assert::uuid($investmentAccountId);
        Assert::uuid($goodwillAccountId);
        Assert::uuid($bargainAccountId);
        Assert::that($investmentAccountId !== $goodwillAccountId && $investmentAccountId !== $bargainAccountId && $goodwillAccountId !== $bargainAccountId, 'Investment, goodwill, and bargain accounts must be distinct.');
        Assert::that($acquisitionSnapshot->bookId === $investeeBookId && ! $consideration->minor->isNegative(), 'Investment requires its investee acquisition snapshot and non-negative consideration.');
        Assert::that(array_is_list($fairValues) && count($fairValues) <= 10000, 'Acquisition fair values require a bounded ordered list.');
        Assert::that(array_sum(array_map(fn (FairValue $fairValue): int => count($fairValue->movements), $fairValues)) <= 10000, 'Acquisition fair-value movement count exceeds its bound.');
        if ($measuredNci !== null) {
            $consideration->sameCurrency($measuredNci);
            Assert::that(! $measuredNci->minor->isNegative(), 'Measured acquisition NCI cannot be negative.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $result = ['investee_book_id' => $this->investeeBookId, 'investment_account_id' => $this->investmentAccountId,
            'goodwill_account_id' => $this->goodwillAccountId, 'bargain_account_id' => $this->bargainAccountId,
            'acquisition_snapshot' => $this->acquisitionSnapshot, 'consideration' => $this->consideration, 'acquisition_rate' => $this->acquisitionRate];
        if ($this->fairValues !== [] || $this->measuredNci !== null) {
            $result += ['fair_values' => $this->fairValues, 'measured_nci' => $this->measuredNci];
        }
        if ($this->goodwillImpairments !== []) {
            $impairments = $this->goodwillImpairments;
            ksort($impairments);
            $result += ['goodwill_impairments' => $impairments, 'impairment_account_id' => $this->impairmentAccountId];
        }

        return $result;
    }
}
