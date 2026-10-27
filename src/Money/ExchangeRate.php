<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger\Money;

use Brick\Math\BigDecimal;
use JsonSerializable;
use NoriaLabs\Ledger\Support\Assert;

final readonly class ExchangeRate implements JsonSerializable
{
    public string $rate;

    public function __construct(
        public Currency $from,
        public Currency $to,
        string $rate,
        public string $asOf,
        public string $source,
        public Rounding $rounding,
    ) {
        $decimal = BigDecimal::of(Assert::decimal($rate))->strippedOfTrailingZeros();
        Assert::that($decimal->isPositive() && $decimal->getScale() <= 18 && strlen((string) $decimal->toScale(18)->getUnscaledValue()) <= 38, 'Exchange rate is outside supported precision.');
        Assert::that($from != $to || $decimal->isEqualTo(1), 'The identity exchange rate must equal one.');
        Assert::that($from->code !== $to->code || $from == $to, 'An exchange rate cannot redefine a currency scale.');
        $this->rate = (string) $decimal;
        Assert::date($asOf);
        Assert::text($source);
    }

    public function convert(Money $money): Money
    {
        Assert::that($money->currency == $this->from, 'Exchange rate source currency mismatch.');

        return Money::rounded($money->rational()->multipliedBy($this->rate), $this->to, $this->rounding);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'rate' => $this->rate, 'as_of' => $this->asOf, 'source' => $this->source, 'rounding' => $this->rounding->value];
    }
}
