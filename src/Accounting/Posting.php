<?php

declare(strict_types=1);

namespace Noria\Ledger\Accounting;

use JsonSerializable;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Reporting\ReportingMovement;
use Noria\Ledger\Support\Assert;

final readonly class Posting implements JsonSerializable
{
    /** @param array<string, string> $dimensions */
    public function __construct(
        public string $accountId,
        public Side $side,
        public Money $amount,
        public ?ExchangeRate $exchangeRate = null,
        public ?string $partyId = null,
        public ?string $dueOn = null,
        public array $dimensions = [],
        public string $description = '',
        public ?Money $valuation = null,
        public ?ReportingMovement $reportingMovement = null,
    ) {
        Assert::uuid($accountId);
        Assert::that($valuation === null ? $amount->minor->isPositive() : $amount->minor->isZero() && $valuation->minor->isPositive(), 'A posting requires a positive amount, or a positive functional-only valuation.');

        if ($partyId !== null) {
            Assert::uuid($partyId);
        }

        if ($dueOn !== null) {
            Assert::date($dueOn);
        }

        foreach ($dimensions as $key => $value) {
            Assert::text($key, 64);
            Assert::text($value, 128);
        }

        Assert::that(strlen($description) <= 1024, 'Posting description is too long.');
    }

    public function opposite(): self
    {
        return new self($this->accountId, $this->side->opposite(), $this->amount, $this->exchangeRate, $this->partyId, $this->dueOn, $this->dimensions, $this->description, $this->valuation, $this->reportingMovement);
    }

    public function withReportingMovement(ReportingMovement $movement): self
    {
        return new self($this->accountId, $this->side, $this->amount, $this->exchangeRate, $this->partyId, $this->dueOn, $this->dimensions, $this->description, $this->valuation, $movement);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['account_id' => $this->accountId, 'side' => $this->side->value, 'amount' => $this->amount, 'exchange_rate' => $this->exchangeRate, 'party_id' => $this->partyId, 'due_on' => $this->dueOn, 'dimensions' => $this->dimensions, 'description' => $this->description, 'valuation' => $this->valuation,
            ...($this->reportingMovement === null ? [] : ['reporting_movement' => $this->reportingMovement])];
    }
}
