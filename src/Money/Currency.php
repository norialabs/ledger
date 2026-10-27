<?php

declare(strict_types=1);

namespace Noria\Ledger\Money;

use Brick\Money\Currency as BrickCurrency;
use Brick\Money\Exception\UnknownCurrencyException;
use JsonSerializable;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Support\Assert;

final readonly class Currency implements JsonSerializable
{
    /** @var non-empty-string */
    public string $code;

    /** @var int<0, 12> */
    public int $scale;

    public function __construct(string $code, int $scale)
    {
        if ($code === '' || preg_match('/^[A-Z][A-Z0-9]{2,15}$/D', $code) !== 1) {
            throw new LedgerException('Invalid currency code.');
        }

        if ($scale < 0 || $scale > 12) {
            throw new LedgerException('Currency scale must be between 0 and 12.');
        }

        $this->scale = $scale;
        $this->code = $code;

        try {
            $iso = BrickCurrency::of($code);
        } catch (UnknownCurrencyException) {
            return;
        }

        Assert::that($scale === $iso->getDefaultFractionDigits(), 'An ISO currency must use its official number of fraction digits.');
    }

    public static function iso(string $code): self
    {
        $currency = BrickCurrency::of($code);

        return new self($currency->getCurrencyCode(), $currency->getDefaultFractionDigits());
    }

    public function brick(): BrickCurrency
    {
        return new BrickCurrency($this->code, null, $this->code, $this->scale);
    }

    /** @return array{code: string, scale: int} */
    public function jsonSerialize(): array
    {
        return ['code' => $this->code, 'scale' => $this->scale];
    }
}
