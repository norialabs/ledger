<?php

declare(strict_types=1);

namespace Noria\Ledger\Money;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\BigRational;
use Brick\Money\AllocationMode;
use Brick\Money\Money as BrickMoney;
use JsonSerializable;
use Noria\Ledger\Support\Assert;

final readonly class Money implements JsonSerializable
{
    private function __construct(public BigInteger $minor, public Currency $currency)
    {
        Assert::that(strlen((string) $minor->abs()) <= 38, 'Amount exceeds the 38-digit storage limit.');
    }

    public static function of(string $major, Currency $currency): self
    {
        $decimal = BigDecimal::of(Assert::decimal($major))->toScale($currency->scale);

        return new self($decimal->getUnscaledValue(), $currency);
    }

    public static function minor(string $minor, Currency $currency): self
    {
        Assert::that(strlen($minor) <= 128 && preg_match('/^-?\d+$/D', $minor) === 1, 'Minor units must be a bounded integer string.');

        return new self(BigInteger::of($minor), $currency);
    }

    public static function rounded(BigRational $major, Currency $currency, Rounding $rounding): self
    {
        return self::of((string) $major->toScale($currency->scale, $rounding->brick()), $currency);
    }

    public function plus(self $other): self
    {
        $this->sameCurrency($other);

        return new self($this->minor->plus($other->minor), $this->currency);
    }

    public function minus(self $other): self
    {
        return $this->plus($other->negated());
    }

    public function negated(): self
    {
        return new self($this->minor->negated(), $this->currency);
    }

    public function multipliedBy(string $factor, Rounding $rounding): self
    {
        return self::rounded($this->rational()->multipliedBy(Assert::decimal($factor)), $this->currency, $rounding);
    }

    public function rational(): BigRational
    {
        return BigRational::of($this->minor)->dividedBy(BigInteger::ten()->power($this->currency->scale));
    }

    public function decimal(): string
    {
        return (string) $this->rational()->toScale($this->currency->scale);
    }

    /** @param non-empty-list<int|string> $weights
     * @return non-empty-list<self>
     */
    public function allocate(array $weights): array
    {
        $total = BigRational::zero();
        $ratios = [];

        foreach ($weights as $weight) {
            $ratio = BigRational::of(is_int($weight) ? $weight : Assert::decimal($weight));
            Assert::that(! $ratio->isNegative(), 'Allocation weights cannot be negative.');
            $total = $total->plus($ratio);
            $ratios[] = $ratio;
        }

        Assert::that($total->isPositive(), 'Allocation weights must have a positive sum.');
        $shares = BrickMoney::ofMinor($this->minor, $this->currency->brick())->allocate($ratios, AllocationMode::FloorToLargestRemainder);

        if ($shares === []) {
            throw new \LogicException('Allocation did not produce any shares.');
        }

        return array_values(array_map(fn (BrickMoney $share): self => new self($share->getMinorAmount()->toBigInteger(), $this->currency), $shares));
    }

    public function sameCurrency(self $other): void
    {
        Assert::that($this->currency == $other->currency, 'Currency or scale mismatch.');
    }

    /** @return array{amount: string, currency: string, scale: int} */
    public function jsonSerialize(): array
    {
        return ['amount' => $this->decimal(), 'currency' => $this->currency->code, 'scale' => $this->currency->scale];
    }
}
