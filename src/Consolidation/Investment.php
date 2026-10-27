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
    public function __construct(public string $investeeBookId, public string $investmentAccountId, public string $goodwillAccountId, public string $bargainAccountId,
        public Snapshot $acquisitionSnapshot, public Money $consideration, public ?ExchangeRate $acquisitionRate = null)
    {
        Assert::uuid($investeeBookId);
        Assert::uuid($investmentAccountId);
        Assert::uuid($goodwillAccountId);
        Assert::uuid($bargainAccountId);
        Assert::that($investmentAccountId !== $goodwillAccountId && $investmentAccountId !== $bargainAccountId && $goodwillAccountId !== $bargainAccountId, 'Investment, goodwill, and bargain accounts must be distinct.');
        Assert::that($acquisitionSnapshot->bookId === $investeeBookId && ! $consideration->minor->isNegative(), 'Investment requires its investee acquisition snapshot and non-negative consideration.');
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['investee_book_id' => $this->investeeBookId, 'investment_account_id' => $this->investmentAccountId,
            'goodwill_account_id' => $this->goodwillAccountId, 'bargain_account_id' => $this->bargainAccountId,
            'acquisition_snapshot' => $this->acquisitionSnapshot, 'consideration' => $this->consideration, 'acquisition_rate' => $this->acquisitionRate];
    }
}
