<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger\Accounting;

use JsonSerializable;
use NoriaLabs\Ledger\Money\Money;
use NoriaLabs\Ledger\Support\Assert;

final readonly class AllocationRequest implements JsonSerializable
{
    public function __construct(public string $bookId, public string $key, public string $debitItemId, public string $creditItemId,
        public Money $amount, public string $effectiveOn, public string $reason, public ?string $gainAccountId = null, public ?string $lossAccountId = null)
    {
        Assert::uuid($bookId);
        Assert::uuid($debitItemId);
        Assert::uuid($creditItemId);
        Assert::text($key, 128);
        Assert::text($reason, 1000);
        Assert::date($effectiveOn);
        Assert::that($amount->minor->isPositive(), 'An allocation amount must be positive.');
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['book_id' => $this->bookId, 'debit_item_id' => $this->debitItemId, 'credit_item_id' => $this->creditItemId,
            'amount' => $this->amount, 'effective_on' => $this->effectiveOn, 'reason' => $this->reason,
            'gain_account_id' => $this->gainAccountId, 'loss_account_id' => $this->lossAccountId];
    }
}
