<?php

declare(strict_types=1);

namespace Noria\Ledger\Documents;

use Noria\Ledger\Accounting\Source;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Support\Assert;

final readonly class PaymentRequest
{
    /** @param array<string, string> $dimensions */
    public function __construct(public string $bookId, public string $key, public string $effectiveOn, public Source $source,
        public string $cashAccountId, public string $controlAccountId, public string $partyId, public Money $amount,
        public bool $receipt = true, public ?ExchangeRate $exchangeRate = null, public ?string $payerReference = null,
        public array $dimensions = [])
    {
        Assert::uuid($bookId);
        Assert::uuid($cashAccountId);
        Assert::uuid($controlAccountId);
        Assert::uuid($partyId);
        Assert::text($key, 128);
        Assert::date($effectiveOn);
        Assert::that($amount->minor->isPositive(), 'Payment amount must be positive.');

        if ($payerReference !== null) {
            Assert::text($payerReference, 128);
        }
    }
}
