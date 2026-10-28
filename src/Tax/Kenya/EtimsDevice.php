<?php

declare(strict_types=1);

namespace Noria\Ledger\Tax\Kenya;

use Noria\Ledger\Support\Assert;

final readonly class EtimsDevice
{
    public function __construct(public string $pin, public string $branchId, public string $communicationKey, public string $environment = 'sandbox', public ?string $serialNumber = null)
    {
        Assert::that(preg_match('/^[A-Z]\d{9}[A-Z]$/D', $pin) === 1, 'A KRA PIN is one letter, nine digits and one letter.');
        Assert::that(preg_match('/^\d{2}$/D', $branchId) === 1, 'An eTIMS branch ID has two digits, for example 00.');
        Assert::that(in_array($environment, ['sandbox', 'production'], true), 'eTIMS environment is sandbox or production.');
        Assert::that(strlen($communicationKey) <= 255, 'The eTIMS communication key is at most 255 characters.');
    }

    public function baseUrl(): string
    {
        return $this->environment === 'production' ? 'https://etims-api.kra.go.ke/etims-api/' : 'https://etims-api-sbx.kra.go.ke/etims-api/';
    }
}
