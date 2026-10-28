<?php

declare(strict_types=1);

namespace Noria\Ledger\Consolidation;

use Brick\Math\BigRational;
use JsonSerializable;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Support\Assert;

final readonly class FairValue implements JsonSerializable
{
    public Money $taxBaseAdjustment;

    /** @param list<array{effective_on: string, amount: Money, counter_account_id: string, tax_base_delta?: Money, tax_rate?: string, recognize_deferred_tax_asset?: bool, rate?: ExchangeRate}> $movements */
    public function __construct(public string $accountId, public Money $adjustment, public string $taxRate = '0',
        public ?string $deferredTaxAssetAccountId = null, public ?string $deferredTaxLiabilityAccountId = null,
        public bool $recognizeDeferredTaxAsset = false, ?Money $taxBaseAdjustment = null, public array $movements = [])
    {
        Assert::uuid($accountId);
        $rate = BigRational::of(Assert::decimal($taxRate));
        Assert::that(! $rate->isNegative() && $rate->isLessThanOrEqualTo(1), 'Deferred tax rates must be fractions between zero and one.');
        $this->taxBaseAdjustment = $taxBaseAdjustment ?? Money::minor('0', $adjustment->currency);
        $adjustment->sameCurrency($this->taxBaseAdjustment);
        foreach ([$deferredTaxAssetAccountId, $deferredTaxLiabilityAccountId] as $id) {
            if ($id !== null) {
                Assert::uuid($id);
                Assert::that($id !== $accountId, 'Fair-value and deferred-tax accounts must be distinct.');
            }
        }
        Assert::that($deferredTaxAssetAccountId === null || $deferredTaxAssetAccountId !== $deferredTaxLiabilityAccountId, 'Deferred-tax asset and liability accounts must be distinct.');
        Assert::that(array_is_list($movements) && count($movements) <= 10000, 'Fair-value movements must be a bounded ordered list.');
        $previous = '';
        foreach ($movements as $movement) {
            Assert::date($movement['effective_on']);
            Assert::uuid($movement['counter_account_id']);
            Assert::that($movement['effective_on'] >= $previous, 'Fair-value movements must follow accounting-date order.');
            $adjustment->sameCurrency($movement['amount']);
            if (isset($movement['tax_base_delta'])) {
                $adjustment->sameCurrency($movement['tax_base_delta']);
            }
            if (isset($movement['tax_rate'])) {
                $taxRate = BigRational::of(Assert::decimal($movement['tax_rate']));
                Assert::that(! $taxRate->isNegative() && $taxRate->isLessThanOrEqualTo(1), 'Movement deferred tax rates must be fractions between zero and one.');
            }
            $previous = $movement['effective_on'];
        }
    }

    public function taxAdjustment(BigRational $carrying, BigRational $taxBase, ?string $taxRate = null, ?bool $recognizeAsset = null): BigRational
    {
        $tax = $carrying->minus($taxBase)->multipliedBy($taxRate ?? $this->taxRate)->negated();

        return $tax->isPositive() && ! ($recognizeAsset ?? $this->recognizeDeferredTaxAsset) ? BigRational::zero() : $tax;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['account_id' => $this->accountId, 'adjustment' => $this->adjustment, 'tax_base_adjustment' => $this->taxBaseAdjustment, 'tax_rate' => $this->taxRate,
            'deferred_tax_asset_account_id' => $this->deferredTaxAssetAccountId, 'deferred_tax_liability_account_id' => $this->deferredTaxLiabilityAccountId,
            'recognize_deferred_tax_asset' => $this->recognizeDeferredTaxAsset, 'movements' => $this->movements];
    }
}
