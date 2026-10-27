<?php

declare(strict_types=1);

namespace Noria\Ledger\Documents;

use JsonSerializable;
use Noria\Ledger\Accounting\Source;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Money\Rounding;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Canonical;

final readonly class TaxedDocumentRequest implements JsonSerializable
{
    /** @param non-empty-list<Obligation> $obligations
     * @param  non-empty-list<TaxLine>  $lines
     * @param  array<string, string>  $taxRoles
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(public string $bookId, public string $key, public string $effectiveOn, public Source $source,
        public DocumentKind $kind, public string $configurationId, public string $controlRole, public array $obligations, public array $lines,
        public array $taxRoles = [], public Rounding $rounding = Rounding::HalfEven, public ?string $roundingRole = null,
        public ?ExchangeRate $exchangeRate = null, public ?string $beneficiaryReference = null, public array $metadata = [])
    {
        Assert::uuid($bookId);
        Assert::uuid($configurationId);
        Assert::text($key, 128);
        Assert::text($controlRole, 128);
        Assert::date($effectiveOn);
        Assert::that($obligations !== [] && $lines !== [] && array_is_list($obligations) && array_is_list($lines), 'A taxed document needs ordered obligations and lines.');
        foreach ($taxRoles as $code => $role) {
            Assert::text($code, 64);
            Assert::text($role, 128);
        }
        if ($roundingRole !== null) {
            Assert::text($roundingRole, 128);
        }
        if ($beneficiaryReference !== null) {
            Assert::text($beneficiaryReference, 128);
        }
        Canonical::json($metadata);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['book_id' => $this->bookId, 'key' => $this->key, 'effective_on' => $this->effectiveOn, 'source' => $this->source,
            'kind' => $this->kind->value, 'configuration_id' => $this->configurationId, 'control_role' => $this->controlRole,
            'obligations' => $this->obligations, 'lines' => $this->lines, 'tax_roles' => $this->taxRoles, 'rounding' => $this->rounding->value,
            'rounding_role' => $this->roundingRole, 'exchange_rate' => $this->exchangeRate, 'beneficiary_reference' => $this->beneficiaryReference, 'metadata' => $this->metadata];
    }
}
