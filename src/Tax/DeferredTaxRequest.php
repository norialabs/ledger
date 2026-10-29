<?php

declare(strict_types=1);

namespace Noria\Ledger\Tax;

use Noria\Ledger\Reporting\Snapshot;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Canonical;

final readonly class DeferredTaxRequest
{
    /** @param array<string, mixed> $inputs */
    public function __construct(public Snapshot $snapshot, public string $key, public string $configurationId, public string $source, public array $inputs)
    {
        Assert::text($key, 128);
        Assert::uuid($configurationId);
        Assert::text($source, 128);
        Canonical::json($inputs);
    }
}
