<?php

declare(strict_types=1);

namespace Noria\Ledger\Documents;

use JsonSerializable;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Canonical;

final readonly class TaxLine implements JsonSerializable
{
    /** @param list<string> $taxCodes
     * @param  array<string, string>  $dimensions
     */
    public function __construct(public string $accountRole, public string $price, public string $quantity, public string $description,
        public array $taxCodes = [], public string $discountFraction = '0', public array $dimensions = [])
    {
        Assert::text($accountRole, 128);
        Assert::decimal($price);
        Assert::decimal($quantity);
        Assert::decimal($discountFraction);
        Assert::text($description, 1024);
        Assert::that(array_is_list($taxCodes) && count($taxCodes) === count(array_unique($taxCodes)), 'Tax codes must be an ordered unique list.');
        foreach ($taxCodes as $code) {
            Assert::text($code, 64);
        }
        Canonical::json($dimensions);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['account_role' => $this->accountRole, 'price' => $this->price, 'quantity' => $this->quantity, 'description' => $this->description,
            'tax_codes' => $this->taxCodes, 'discount_fraction' => $this->discountFraction, 'dimensions' => $this->dimensions];
    }
}
