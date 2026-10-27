<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger\Inventory;

use Brick\Math\BigDecimal;
use JsonSerializable;
use NoriaLabs\Ledger\Support\Assert;

final readonly class Quantity implements JsonSerializable
{
    public BigDecimal $value;

    public function __construct(string $value)
    {
        $this->value = BigDecimal::of(Assert::decimal($value))->toScale(12);
        Assert::that($this->value->abs()->isLessThan('100000000000000000000000000'), 'Quantity exceeds the supported range.');
    }

    public function decimal(): string
    {
        return (string) $this->value;
    }

    public function jsonSerialize(): string
    {
        return $this->decimal();
    }
}
