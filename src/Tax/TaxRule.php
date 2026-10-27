<?php

declare(strict_types=1);

namespace Noria\Ledger\Tax;

use Brick\Math\BigRational;
use JsonSerializable;
use Noria\Ledger\Support\Assert;

final readonly class TaxRule implements JsonSerializable
{
    public function __construct(public string $code, public string $rate, public string $validFrom, public ?string $validUntil,
        public string $policyVersion, public bool $inclusive = false, public bool $compound = false)
    {
        Assert::text($code, 64);
        Assert::text($policyVersion, 128);
        Assert::decimal($rate);
        Assert::that(! BigRational::of($rate)->isNegative(), 'Tax rate cannot be negative.');
        Assert::date($validFrom);

        if ($validUntil !== null) {
            Assert::date($validUntil);
            Assert::that($validUntil >= $validFrom, 'Tax validity dates are reversed.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['code' => $this->code, 'rate' => $this->rate, 'valid_from' => $this->validFrom, 'valid_until' => $this->validUntil,
            'policy_version' => $this->policyVersion, 'inclusive' => $this->inclusive, 'compound' => $this->compound];
    }
}
