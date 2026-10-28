<?php

declare(strict_types=1);

namespace Noria\Ledger\Money;

final readonly class StoredRate
{
    public function __construct(
        public string $id,
        public Currency $base,
        public Currency $quote,
        public RateType $type,
        public string $effectiveOn,
        public int $revision,
        public string $rate,
        public string $source,
        public ?string $reason,
        public string $recordedAt,
        public string $actorId,
    ) {}

    public function exchangeRate(Rounding $rounding = Rounding::HalfEven): ExchangeRate
    {
        return new ExchangeRate($this->base, $this->quote, $this->rate, $this->effectiveOn, 'ledger-rate:'.$this->id, $rounding);
    }
}
