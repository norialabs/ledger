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
    /** @param list<FairValue> $fairValues */
    public function __construct(public string $investeeBookId, public string $investmentAccountId, public string $goodwillAccountId, public string $bargainAccountId,
        public Snapshot $acquisitionSnapshot, public Money $consideration, public ?ExchangeRate $acquisitionRate = null, public array $fairValues = [], public ?Money $measuredNci = null)
    {
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

        return $result;
    }
}
