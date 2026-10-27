<?php

declare(strict_types=1);

namespace Noria\Ledger\Access;

use Noria\Ledger\Support\Assert;

final readonly class Scope
{
    public function __construct(public string $tenantId, public string $actorId, public ?string $firmId = null)
    {
        Assert::uuid($tenantId);
        Assert::text($actorId, 128);

        if ($firmId !== null) {
            Assert::text($firmId, 128);
        }
    }
}
