<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger\Accounting;

use JsonSerializable;
use NoriaLabs\Ledger\Support\Assert;

final readonly class Source implements JsonSerializable
{
    public function __construct(public string $type, public string $reference)
    {
        Assert::text($type, 64);
        Assert::text($reference, 128);
    }

    /** @return array{type: string, reference: string} */
    public function jsonSerialize(): array
    {
        return ['type' => $this->type, 'reference' => $this->reference];
    }
}
