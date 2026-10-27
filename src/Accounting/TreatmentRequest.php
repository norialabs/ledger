<?php

declare(strict_types=1);

namespace Noria\Ledger\Accounting;

use JsonSerializable;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Canonical;

final readonly class TreatmentRequest implements JsonSerializable
{
    /** @param array<string, mixed> $inputs */
    public function __construct(public string $bookId, public string $key, public string $effectiveOn, public Source $source,
        public string $treatment, public string $version, public string $configurationId, public array $inputs = [])
    {
        Assert::uuid($bookId);
        Assert::uuid($configurationId);
        Assert::text($key, 128);
        Assert::text($treatment, 128);
        Assert::text($version, 128);
        Assert::date($effectiveOn);
        Canonical::json($inputs);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['book_id' => $this->bookId, 'key' => $this->key, 'effective_on' => $this->effectiveOn, 'source' => $this->source,
            'treatment' => $this->treatment, 'version' => $this->version, 'configuration_id' => $this->configurationId, 'inputs' => $this->inputs];
    }
}
