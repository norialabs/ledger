<?php

declare(strict_types=1);

namespace Noria\Ledger\Documents;

use Noria\Ledger\Accounting\Source;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Canonical;

final readonly class DocumentRequest
{
    /** @param non-empty-list<Obligation> $obligations
     * @param  non-empty-list<Charge>  $charges
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(public string $bookId, public string $key, public string $effectiveOn, public Source $source,
        public DocumentKind $kind, public string $controlAccountId, public array $obligations, public array $charges,
        public ?ExchangeRate $exchangeRate = null, public ?string $beneficiaryReference = null, public array $metadata = [])
    {
        Assert::uuid($bookId);
        Assert::uuid($controlAccountId);
        Assert::text($key, 128);
        Assert::date($effectiveOn);
        Assert::that($obligations !== [] && $charges !== [], 'A document needs obligations and charges.');
        Canonical::json($metadata);

        if ($beneficiaryReference !== null) {
            Assert::text($beneficiaryReference, 128);
        }
    }
}
