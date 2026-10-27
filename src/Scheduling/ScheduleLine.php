<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger\Scheduling;

use JsonSerializable;
use NoriaLabs\Ledger\Money\Money;
use NoriaLabs\Ledger\Support\Assert;

final readonly class ScheduleLine implements JsonSerializable
{
    public function __construct(public string $dueOn, public Money $amount)
    {
        Assert::date($dueOn);
        Assert::that(! $amount->minor->isNegative(), 'Schedule amounts cannot be negative.');
    }

    /** @return array{due_on: string, amount_minor: string} */
    public function jsonSerialize(): array
    {
        return ['due_on' => $this->dueOn, 'amount_minor' => (string) $this->amount->minor];
    }
}
