<?php

declare(strict_types=1);

namespace Noria\Ledger\Reporting;

use JsonSerializable;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Canonical;

final readonly class StatementColumn implements JsonSerializable
{
    /** @param array<string, string> $dimensions */
    public function __construct(public string $key, public string $label, public Snapshot $snapshot, public string $startsOn, public array $dimensions = [])
    {
        Assert::text($key, 128);
        Assert::text($label);
        Assert::date($startsOn);
        Assert::that($startsOn <= $snapshot->effectiveOn, 'Statement column starts after it ends.');
        foreach ($dimensions as $dimension => $value) {
            Assert::text($dimension, 64);
            Assert::text($value, 128);
        }
        Canonical::json($dimensions);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'snapshot' => $this->snapshot, 'starts_on' => $this->startsOn, 'dimensions' => $this->dimensions];
    }
}
